# Hazdar 全民風災回報系統 — 技術規格 v0.3

> v0.2（2026-07-07）：依 PM 會議決議追加 §11 修訂。§1–§10 為 v0.1 基線，與 §11 牴觸處以 §11 為準（牴觸點已於原文標注）。

- 基底:fork [ratdar](https://github.com/wildcat1977/ratdar)(Laravel 13 / Livewire 4 / Filament 5 / Horizon / PostgreSQL / Leaflet)
- 範圍:台北市(行政區沿用 ratdar 既有 GeoJSON)
- 讀者:實作者(人或 Claude Code)。所有標注「⚠ 驗證」的項目,實作時以官方 API 文件與 ratdar repo 實際程式碼為準,不得憑記憶硬寫。

---

## 1. 問題與目標

颱風/豪雨災時,官方災情彙整速度趕不上現場變化。讓民眾以最低摩擦回報四類災情(淹水、道路中斷、停電、結構受損),經自動佐證與官方核實分層後,即時呈現在民眾地圖與 EOC dashboard 上,並整合 CWA/NCDR 官方資料形成單一情勢圖。

**成功條件(驗收標準,不是願望清單):**

1. 外部 API(CWA/NCDR/data.taipei)全數 timeout 時,回報提交成功率不受影響;前端對應面板顯示「資料更新中斷 + 最後更新時間」,而非壞版面。
2. NCDR 發布新警戒後 ≤ 5 分鐘出現在跑馬燈。
3. 地圖點位 API 在 50,000 筆回報的資料量下,任一 viewport 回應 payload ≤ 300KB、p95 ≤ 800ms。
4. 同一 geohash7 網格內,3 個不同帳號在 6 小時內回報同類災情 → 該群點位自動升級 corroborated,統計卡片同步反映。
5. 任一 scheduled fetch 失敗必須記錄 log 並反映在 dashboard footer 狀態燈(黃=延遲、紅=中斷)。**禁止默默跳過。**

## 2. MVP 範圍與非目標

**範圍(依 Phase 切分,見 §9):**
分類回報 + 民眾地圖、CWA/NCDR/圖層整合、EOC dashboard、物資看板(Filament 官方手動維護)。*(v0.2:物資看板由「交通與清運資訊彙整」取代,見 §11.5)*

**非目標(明確不做,防 scope creep):**

| 不做 | 原因 |
|---|---|
| 民間物資登記/媒合 | 完整獨立 domain(供需配對、志工認證),Phase 2 之後再議 |
| Mesh 通訊、離線 PWA | 已確認 mesh 為視覺元素;離線降級是獨立題目 |
| WebSocket(Reverb)即時推播 | `wire:poll.10s` 對 EOC 大屏足夠,簡單優先 |
| 多縣市 | 行政區資料與外部資料源都以台北市為前提,架構不刻意封死即可 |
| PostGIS | geohash 分桶足以支撐 MVP 量級;PostGIS 列為 scale-up path(§4.4) |
| severity 欄位 | 圖面上沒有對應呈現;淹水深度本身就是嚴重度 proxy。P2 |

**設計修正(相對原始 mockup):** dashboard footer 的「Mesh 節點 34 / 網路狀態 FULL BAND」不實作為假數據。EOC 工具上出現虛構指標會侵蝕整個系統的可信度。以真實系統健康指標取代:各外部資料源狀態燈、Horizon queue 深度、今日回報數/官方核實數。

## 3. 沿用 ratdar 的部分(不重寫)

- 回報 pipeline:Livewire report-form(GPS + 照片)、Horizon queue、Filament 後台
- Socialite 登入(Google + LINE LIFF)——災時主入口預期是 LINE
- Leaflet 地圖基底與暗色主題
- ImageModerationService(Claude Vision),職責擴充見 §6.3
- 反濫用機制(cooldown 等,⚠ 驗證 ratdar 實際實作後沿用並依 §10 調參)

**Phase 0 規則:先讀 ratdar 的 migrations 與 model,再寫 delta migration。** 以下 schema delta 是「意圖」,欄位命名配合既有慣例。

## 4. Schema Delta

### 4.1 `reports` 修改

| 欄位 | 型別 | 說明 |
|---|---|---|
| `category` | enum: `flood` / `road_blocked` / `power_outage` / `structural_damage` | 必填 |
| `water_depth_cm` | smallint, nullable | 僅 `flood` 顯示的選填欄位 |
| `geohash7` | char(7), indexed | 寫入時由 lat/lng 計算(precision 7 ≈ 150m 網格),佐證與聚合用 |
| `verification_level` | enum: `unverified` / `corroborated` / `official` | 預設 `unverified`;與既有審核狀態**分離為兩個正交欄位**(內容審核 ≠ 災情核實) |
| `resolved_at` | timestamp, nullable | 官方標記已排除 |
| 照片欄位 | 改 nullable | 災時拍照非必要條件,見 §6.1 |

既有審核狀態欄位(pending/approved/rejected,⚠ 驗證實際命名)保留,語義收斂為「內容適切性審核」。

複合索引:`(category, created_at)`、`(geohash7, category, created_at)`。

### 4.2 新增資料表

```
weather_observations   -- CWA 觀測歷史,供 24H 趨勢圖
  station_id, observed_at, temp, rain_1h, wind_speed, visibility, raw jsonb
  unique(station_id, observed_at);保留 7 天,daily prune

external_alerts        -- NCDR CAP 警戒快取
  source, cap_id unique, alert_type, headline, severity,
  areas jsonb, effective_at, expires_at, raw jsonb

shelters               -- 收容所(data.taipei 同步 + Filament 可手動切開設狀態)
  name, lat, lng, capacity, is_open, source, external_id unique

dashboard_resources    -- 物資看板(純官方手動維護)
  label, current_qty, max_qty, unit, sort_order
```

即時性快取(雷達回波圖檔、颱風路徑 jsonb、CWA 即時觀測最新值)走 Redis,不進表;各源同時維護 `last_success_at` 供健康指標讀取。

### 4.3 佐證(corroboration)規則 — 純程式,不用 AI

回報寫入後觸發 event listener:

```
同 geohash7 + 同 category + created_at 於近 6 小時滑動窗
+ distinct user ≥ 3  →  該群全部 UPDATE 為 corroborated
```

deterministic SQL 一發解決。官方核實(→ `official`)只能由 Filament 後台人工操作。

### 4.4 Scale-up path(現在不做,只避免封死)

點位量級再上去時:geohash 聚合改 PostGIS `ST_ClusterDBSCAN` + GiST index。lat/lng 欄位不要被 accessor 包死,保留裸值可讀。

## 5. 外部資料整合層

原則:**scheduled command 拉 → 落地(表或 Redis)→ 前端只讀落地資料**。任何前端請求不得直接打外部 API。所有 fetch 失敗記 log + 更新健康狀態,靜默失敗視為 bug。

| 來源 | 內容 | 週期 | 落地 | 備註 |
|---|---|---|---|---|
| CWA 開放資料平台(opendata.cwa.gov.tw,授權碼制) | 測站即時觀測(溫度/時雨量/風速/能見度)、雨量歷史 | 5 min | `weather_observations` + Redis 最新值 | ⚠ 驗證 dataset ID 與欄位(平台常改版) |
| CWA | 颱風警報/路徑 | 30 min(警報期 10 min) | Redis jsonb | 路徑 layer 為 P1,可砍 |
| CWA | 雷達回波整合圖(png,固定地理範圍) | 10 min | 後端 proxy + cache 圖檔 | 前端 Leaflet `ImageOverlay`;proxy 避免 CORS 與打爆來源 |
| NCDR 民生示警(CAP) | 土石流/淹水等警戒 | 5 min | `external_alerts`,依 cap_id upsert | 跑馬燈 = 未過期 + 涵蓋台北市之警戒。⚠ 驗證取用方式是否需申請 |
| 水利署/NLSC 淹水潛勢圖 | WMTS/WMS 圖磚 | — | 無(前端直掛) | ⚠ 驗證服務 URL 與嵌入授權;拿不到就先不掛這層,不阻塞 |
| data.taipei | 收容所清單 | daily | `shelters` upsert | 災時開設狀態以後台手動為準 |

Scheduler 統一掛 `routes/console.php`(⚠ 配合 ratdar 既有排程寫法),每支 command 可獨立手動執行。

## 6. 核心流程

### 6.1 回報 pipeline

1. 表單:分類(必選,四鈕)→ GPS 自動定位(可微調)→ 照片(**選填**)→ 淹水時追加深度選填。
2. 寫入即回應(p95 < 500ms),地址反查與 AI 審核全部 queue 後補。
3. **無照片**:直接以 `unverified` 上圖(最低視覺層級),靠佐證升級。
4. **有照片**:AI 審核通過前照片不公開,點位照常顯示。
5. 反濫用:沿用 ratdar cooldown,另加每帳號每小時回報上限(參數見 §10)。

Nominatim 反查降級:災時地址非關鍵欄位,queue 塞車不得阻塞回報寫入;佇列深度超閾值時跳過反查、事後批次補。

### 6.2 AI 使用邊界(能用程式就用程式)

- **用 Claude Vision:**照片內容適切性 + 「照片是否吻合所選分類」的判斷(不吻合 → 標記供人工複核,不自動退件)。
- **不用 AI:**佐證/聚合/去重(geohash + SQL)、統計、路由重試、警戒 parsing——全部 deterministic 程式。

### 6.3 統計定義

- 各分類卡片:近 24h count;`+N 過去1H` = created_at > now−1h。排除 rejected(內容審核退件),**不排除** unverified——災時「可能有事」的資訊價值大於等核實。
- Footer:今日回報數、官方核實數(`verification_level = official`)、各資料源狀態燈、Horizon queue 深度。
- 「在線公民」若保留:以近 15 分鐘活躍 session 數近似,不做 presence channel。可砍。

## 7. 前端頁面

### 7.1 民眾地圖頁(改造 ratdar radar 頁)

- 分類篩選 chips(對應圖例配色:淹水藍/道路橘/停電黃/結構紅/收容所綠)。
- 視覺分層:`unverified` 半透明 → `corroborated` 實心 → `official` 加框/徽章;`resolved` 預設隱藏。*(v0.2:resolved 改為第三態顯示,見 §11.8)*
- 圖層開關:雷達回波、淹水潛勢、收容所;颱風路徑(P1)。

### 7.2 點位 API(取代 ratdar 前端全量模式)

**這是相對 ratdar 最重要的架構改動。** ratdar 的 5000 上限 + 前端 Haversine 在災時必爆。

- Server-side bbox query:`?bbox=&categories=&zoom=`。
- zoom 低 → 依 geohash(precision 隨 zoom 4~6)聚合回傳 `{center, count, dominant_category}`;zoom 高(約 ≥15)→ 回傳個別點位。
- 單次回應筆數上限 + payload 上限(驗收標準 §1-3)。回應 cache 15~30s(依 bbox+filter 為 key)。

### 7.3 EOC Dashboard(`/dashboard`,唯讀)*(v0.2:併入首頁單頁,`/dashboard` 302 轉向,見 §11.1)*

- 版面同 mockup:左欄 CWA 即時 + 24H 雨量趨勢 + NCDR 燈號;中央地圖(同 7.2 API);右欄統計卡片 + 物資看板;頂部警戒跑馬燈;footer 真實健康指標(§2 設計修正)。
- 更新:`wire:poll.10s`(統計/跑馬燈)、地圖 layer 各自依源週期刷新。
- 無權限概念的公開唯讀頁即可;若要限縮再加 gate,MVP 不做角色系統。

## 8. 韌性要求

- 回報寫入路徑與外部整合層**零共用依賴**(不同 queue、外部 fetch 不在請求生命週期內)。
- 讀取端(民眾地圖、dashboard)全走 cache,可加 CDN;目標:CWA/NCDR 全掛時整站仍可回報、可看圖。
- 每支外部 fetch 有 timeout(≤10s)與獨立 try/catch;失敗 → log + 健康狀態降級,不 retry storm(下一輪 schedule 自然重試)。

## 9. Phase 切分

| Phase | 內容 | 出貨判準 |
|---|---|---|
| 0 | fork/rename、清除鼠患 domain、讀既有 migrations 後寫 delta、seeder、行政區 GeoJSON 確認 | 測試綠、本地起得來 |
| 1a | 分類回報 + 佐證機制 + 民眾地圖(bbox/聚合 API) | 驗收 §1-3、§1-4;**此階段即可獨立上線** |
| 1b | CWA 觀測 + NCDR 警戒 + 雷達回波 overlay + 淹水潛勢 layer + 收容所同步 | 驗收 §1-1、§1-2 |
| 1c | EOC dashboard + 物資看板(Filament)+ footer 健康指標 | 驗收 §1-5 |
| 2(parking lot) | Reverb 推播、PWA 離線、物資媒合、志工系統、多縣市、PostGIS、severity | 不在本 spec |

每個 Phase 結束做檢查點:做了什麼 / 驗證了什麼 / 剩什麼。

## 10. Open Questions

| # | 問題 | 阻塞性 | 誰答 |
|---|---|---|---|
| 1 | NCDR CAP 資料取用是否需申請帳號/金鑰 | 阻塞 1b | 實作時查平台文件 |
| 2 | CWA 各 dataset ID 與回應欄位 | 1b 實作中驗證 | 同上 |
| 3 | 淹水潛勢 WMTS 服務 URL 與公開嵌入授權 | 非阻塞(拿不到就不掛) | 同上 |
| 4 | ratdar `reports` 實際欄位/審核狀態命名、cooldown 實作 | 阻塞 0 | Phase 0 讀 repo |
| 5 | 災時反濫用參數:cooldown 秒數、每帳號每小時上限、無照片回報是否另設較嚴上限 | 1a 上線前 | wildcat(產品決策) |
| 6 | 「認證志工(在線)」面板:手動數字或砍掉 | 非阻塞 | wildcat |

---

## 11. v0.2 修訂(2026-07-07 PM 會議決議)

原則不變:能用程式就用程式、外部資料走落地、回報寫入路徑零共用依賴。視覺全面調整(候選人感、自製 icon 圖檔)列 **v0.3**,本版不做。

### 11.1 首頁與 dashboard 整合(單頁取代兩頁)

- `/` 保留現有全螢幕互動地圖為底(回報、篩選 chips、圖層開關不變),dashboard 內容改為左右兩欄**浮動可收合卡片**:
  - 左欄:CWA 即時觀測、24H 雨量趨勢、NCDR 燈號。
  - 右欄:統計卡片、交通與清運資訊彙整(§11.5,取代物資看板)。
  - 每張卡右上角 ﹀ 收合/展開,狀態記 localStorage;行動版預設收合。
- 保留原 dashboard 的頂部 nav 與 footer,上下包住整頁;頂部警戒跑馬燈照舊。
- **footer 變更(2026-07-07 補充)**:資料源狀態燈自公開 footer 移除,改放「最新消息/公告」連結;狀態燈移至 Filament 後台儀表,§1-5 驗收的失敗可視性由後台承接,不得刪除。
- `/dashboard` 302 轉向 `/`(EOC 大屏同用此頁);既有 eoc-dashboard 拆成卡片子元件重用,`wire:poll` 週期不變。

### 11.2 雷達回波對位校正

- **根因(已確認)**:CV1_3600.png 為等經緯(equirectangular)投影,Leaflet(EPSG:3857) `ImageOverlay` 線性拉伸 → 台灣緯度像素北偏約 20km,與回報症狀吻合。
- 修法:`FetchRadarEcho` 落地圖檔時以 GD 逐列重採樣(等經緯 → Mercator),bounds 與前端完全不動。
- 驗收:以行政區邊界基準點對照,目測偏移 < 2km。

### 11.3 免責聲明彈窗

文案(全文照用):「使用前請注意:本地圖彙整網友回報與氣象局雨量回報所得之災情資訊,僅供大眾參考,不代表市府公告,緊急事故請直接撥打119/1999,切勿僅依賴本平台等待救援。希望市民朋友保護自身安全,如非必要請勿出門。」

- 進站:每 browser session 跳一次(sessionStorage)。
- 回報:每次開啟回報表單再跳一次,按同意才能繼續。
- 開關:`HAZDAR_DISCLAIMER_REPORT_MODE=every|session|off`(config/hazdar.php,預設 `every`),未來調整免改碼。

### 11.4 最新消息頁 + 上稿系統

- 不另建 model,擴充 `announcements`:`category` enum(`school_closure` 停班停課/`home_safety` 居家災防/`gov_press` 市府與氣象署/`incident` 災情通報)、`body`(富文本)、`link_url`、`is_pinned`、`published_at`。
- `/news` 公開頁:置頂優先、`published_at` 倒序、分類徽章;cache ≤ 60s。
- 既有 Filament Announcements resource 擴充為上稿系統;跑馬燈沿用 `is_active`——一套後台餵兩處。

### 11.5 交通與清運資訊彙整看板(取代物資看板)

- 新表 `info_links`:`section` enum(`transit`/`water_gate`/`sanitation`/`shelter`)、`label`、`url`、`status_text`、`sort_order`、`is_active` + Filament resource。
- 卡片四區塊:大眾交通/水門與抽水站/清運與環保局/避難收容所;收容所區塊直接顯示既有 `shelters` 開設數。
- 物資看板:卡片自 UI 移除;`dashboard_resources` 表與後台保留、隱藏導覽(Phase 2 視需求回歸)。
- 水門/抽水站**即時資料**(gis.gov.taipei、data.taipei dataset `2bbfb30e`)列 P2,本版只放連結 + 後台手動狀態文字。

### 11.6 圖卡專區

- 新表 `promo_cards`:`title`、`image`、`group` enum(`shen` 沈伯洋/`councilor` 議員候選人)、`sort_order`、`is_active` + Filament 上傳。
- `/cards` 頁:兩區格狀、lightbox 看大圖、可下載分享;首頁地圖懸浮社群 icon 連入。

### 11.7 回報分類後台化(含新增 2 類)

- 新表 `report_categories`:`slug`(unique,**建立後唯讀**,程式與 reports.category 以 slug 為鍵)、`name`、`emoji`、`color`(hex)、`sort_order`、`is_active`、`extra_field` jsonb nullable `{label, unit, min, max, presets?: [{value, hint}]}`(**單一選填數值欄位**;presets 為表單快選鈕,如淹水 10=腳踝/30=小腿)。
- Seed 6 類:既有 4 類 + `water_outage`(停水/紫)、`falling_object`(大型物品含樹木掉落/棕);`flood` 帶 `extra_field {積水深度, cm, 0, 500}`。
- `reports.water_depth_cm` 更名 `extra_value`(integer,資料保留);表單依所選分類的 `extra_field` 動態顯示數值欄,驗證規則由設定推導。
- 前端分類鈕/篩選 chips/圖例/統計卡與點位 API 分類白名單改讀 DB(cache 60s,後台變更時 bust)。
- 刪除規則:**已有回報之分類禁止刪除,只能停用**;停用=關閉新回報入口,既有點位與統計照常顯示(災情資料不得憑空消失)。
- Claude Vision「照片是否吻合分類」prompt 的分類名改讀 DB。
- **明確不做(v0.2)**:多欄位 form-builder、select/text 型自訂欄位——目前僅淹水一個數值欄位需求,出現第二種型態再擴。

### 11.8 地圖 icon 三態(回報/已通報/已排除)

- `reports` 加 `notified_at` timestamp;Filament 動作手動標記「已通報」,「已排除」沿用 `resolved_at`。
- 推導(單向優先序):`resolved_at` → 已排除(灰化+勾)> `notified_at` → 已通報(原色+徽章)> 否則 → 回報(原色)。
- CSS/SVG divIcon 組合三態,**不出 6×3 張圖檔**(自製 icon 圖檔列 v0.3)。
- 變更 §7.1:resolved 從「預設隱藏」改為「顯示第三態,篩選可關」。`verification_level` 半透明/實心分層保留,與處理狀態正交。

### 11.9 驗收標準(v0.2 追加)

1. `/dashboard` 302 → `/`;首頁卡片可收合且狀態跨 reload 保留。
2. 雷達回波對位目測誤差 < 2km。
3. 免責彈窗行為符合 §11.3;切換 env 模式即生效。
4. 後台停用分類 → 回報表單 ≤ 60s 內不再出現該鈕,既有點位照常顯示。
5. 後台新增分類(含數值欄位)→ **不改碼**即可回報並上圖。
6. news 上稿/下架 ≤ 60s 反映於 `/news` 與跑馬燈。
7. 已通報/已排除標記即時反映地圖 icon 與圖例。

### 11.10 v0.2 Phase 切分

| Phase | 內容 | 狀態 |
|---|---|---|
| 2a | §11.2 雷達校正 + §11.3 免責彈窗 | ✅ 已出貨（雷達重採樣、進站/回報兩段式免責彈窗） |
| 2b | §11.7 分類後台化 + §11.8 三態 | ✅ 已出貨（report_categories 表 + Filament CRUD、地圖三態、新增停水/大型物品掉落） |
| 2c | §11.4 news + §11.5 資訊彙整 + §11.6 圖卡 | ✅ 已出貨（/news 上稿系統、交通清運看板取代物資看板、/cards 圖卡專區） |
| 2d | §11.1 首頁整合 | ✅ 已出貨（首頁浮動可收合卡片、/dashboard 302→/、健康燈號移入 Filament widget、footer 改新聞連結） |

每個 Phase 結束做檢查點:做了什麼/驗證了什麼/剩什麼。

**v0.2 全數完成（2026-07-07）。** 全套 153 測試綠。後續 v0.3（視覺調整）待辦已彙整於 `.superpowers/sdd/progress.md` 的 backlog 區。

---

## 12. v0.3 修訂(2026-07-08 視覺/UX 調整)

純視覺與 UX 調整:配色、地圖 pin、手機版重整、圖卡改 banner。**不動**回報寫入路徑、外部資料落地、後台資料模型(report_categories/announcements/info_links/promo_cards 全部沿用)。JS(Leaflet)與 CSS 變更以瀏覽器截圖/操作驗證;可 PHP 單測者(payload/路由/footer/nav)照測。

### 12.1 全站配色 → `#002923`

- `resources/css/app.css`:`--color-radar-bg`、body 底、地圖 tile 後方底 `#0d1117 → #002923`;`layouts/app.blade.php` body class 與 `<meta name="theme-color">` 同步。
- 浮動卡片 `components/dashboard-card.blade.php`:surface `#0f1522/95 → #003a30`(微提亮,與 `#002923` 底做出層次分離)。

### 12.2 上下實色帶 + 放大跑馬燈

- **頂帶**:目前左上半透明浮動 pill → 橫跨頂部的 `#002923` 實色帶;左放 banner(§12.4),右放地圖控制項 + 移入的 nav 連結(§12.6)。
- **底帶**:`#002923` 實色帶,內含(由上而下):管理員公告 → 我的回報狀態(登入) → 跑馬燈 → 立即回報按鈕 → footer(§12.6)。
- 跑馬燈字級 `text-[11px] → text-[13px]`(≥13px);資料源不變(NCDR 警戒 + 24h 統計 + 最近回報,見 `radar-map` `getTickerMessagesProperty`)。

### 12.3 地圖三態 pin(水滴 pin + emoji)

- `resources/js/app.js` `buildPointsLayer`:`L.circleMarker` → `L.marker` + `L.divIcon`。divIcon 為分類色水滴 pin(SVG/CSS),中央放該分類 emoji(停水=水、停電=電…),pin 尖端 `iconAnchor` 對座標。
- 三態由 `status`(`p.st`)決定:回報(`null`)=實色 `opacity 1`;已通報(`notified`)=整枚 `opacity 0.2`;已排除(`resolved`)=`filter: grayscale(1)`。
- `verification_level`(未核實/多方佐證/官方核實)**退出地圖視覺**,只留在點擊 popup(popup 已顯示,不動);`VERIFICATION_STYLE` 於 marker 渲染移除。
- `categoryMeta` payload(`radar-map.blade.php` `categoryMeta`、`app.js` `CATEGORY_META`)補 `emoji` 欄位。聚合圓 `buildClustersLayer` 維持數字徽章不變。
- **變更 §11.8**:「`verification_level` 半透明/實心分層保留」→ 改為「地圖上移除、只留 popup」。

### 12.4 Banner

- 頂帶左側 HAZDAR 文字 pill → `<img src="{{ asset('Hazdar-Banner.png') }}">`(既有檔 300×100 RGBA,顯示高 ~40px、寬自動)。

### 12.5 圖卡輪播 banner(取代 `/cards` 獨立頁)

- 新 `promo-banner` 元件:放在「交通與清運資訊」卡下方,Alpine 自動輪播(~5s)啟用中 `PromoCard`,點擊開 lightbox 看大圖;**無啟用圖卡則不渲染**。
- 資料併入 `eoc-dashboard` `sharedCardData()` 快取(純陣列 `{title, image_url}`,遵守禁存 Eloquent 物件規則)。
- **移除獨立頁**:`/cards` 頁 + gallery 元件刪除,route 改 `Route::redirect('/cards', '/', 302)`;頂部 🖼️ 圖卡連結、footer 圖卡連結一併移除。**Filament `PromoCard` 後台管理保留**。
- **變更 §11.6**:`/cards` 獨立頁退場,改首頁 banner;首頁地圖社群 icon 連入取消。

### 12.6 Footer 重整 + nav 移動

- footer 併入底帶 `#002923`。
- **左下**:各分類最新消息篇數 — 用 `Announcement::published()` 依 `category` 分組計數,**只列有已發佈的分類**(`停班停課 N`/`居家災防 N`/`市府與氣象署 N`/`災情通報 N`),整組連到 `/news`。
- **隱藏**:回報清單(`reports`)、透明度報告(`transparency`)、統計地圖(`stats`)三連結(route 保留,footer 不連)。**移除**:圖卡專區連結。
- **移到頂帶 nav**:我的回報(`profile`,登入才顯示)、聯絡管理員(`open-contact-form` 事件鈕)。

### 12.7 手機右側 IITC 圖層面板 + 卡片 modal

- 新 mobile-only 元件(`lg:hidden`):右緣收合 tab(⟩),點擊滑出面板。
- **面板上半**:圖層 checkbox 清單 — 各啟用分類 + 收容所 + 雷達回波 + 淹水潛勢(有設定才列) + 三態篩選(§12.8);每個 checkbox 驅動與桌機 chips 相同的 `mapController` 圖層開關(`data-map-chip` 對應動作),狀態與地圖圖層單一同步。
- **面板下半**:各資訊卡按鈕 → 點開全螢幕 bottom-sheet modal(CWA 即時/24H 雨量/NCDR 警戒/事件統計/交通清運)+ 圖卡 banner(§12.5)。
- **手機不再顯示頂部 chips**(桌機 chips 保留);取代現有會互疊的手機底部卡片抽屜(`eoc-dashboard.blade.php` `lg:hidden` 區塊)。
- 地圖搜尋/明暗切換/縮放留 Leaflet 原位;右側 tab 置於右中緣,避開上述控制項。

### 12.8 回報三態篩選(併入圖層選擇)

- 桌機:單一「已排除」chip → 三態 chip:回報 / 已通報 / 已排除(皆預設開,對應現行 `resolved` 預設開)。
- **已排除**沿用 `show_resolved` 伺服端參數(效能:隱藏時不下發已排除點,不變);**回報 / 已通報**於 `app.js` 以 `activeStatuses` set 客戶端過濾已下發的 active 點(`p.st` `null → reported`)。
- 手機於 §12.7 面板以同一組 checkbox 呈現。

### 12.9 驗收標準(v0.3)

1. 全站底 `#002923`、卡片 `#003a30`;頂/底為實色帶非透明浮動 pill。
2. 地圖 pin 為分類色水滴 + emoji,三態(實色/20%/灰階)視覺正確;查核等級只在 popup。
3. 頂帶顯示 banner 圖(非文字);跑馬燈於底帶且字級 ≥13px。
4. 有啟用圖卡 → 交通清運卡下方單一輪播 banner;無則不顯示;`/cards` 302 → `/`。
5. footer 顯示有發佈之分類篇數並連 `/news`;無回報清單/透明度/統計/圖卡連結;頂帶 nav 有我的回報(登入)/聯絡管理員。
6. 手機右側面板可勾選開關圖層(含回報/已通報/已排除三態)並開啟卡片 modal;地圖控制項不被蓋、頂部 chips 不出現於手機。
7. `categoryMeta` payload 含 `emoji`;`buildPointsLayer` 無 `circleMarker`。

### 12.10 v0.3 Phase 切分

| Phase | 內容 | 狀態 |
|---|---|---|
| 3a | §12.1 配色 + §12.2 上下實色帶/跑馬燈 + §12.4 banner + §12.6 footer/nav | ✅ 已出貨（配色 #002923/卡片 #003a30 擴及全站頁面、頂/底實色帶、banner、跑馬燈 13px、footer 分類新聞篇數+隱藏連結、nav 移入我的回報/聯絡管理員） |
| 3b | §12.3 三態 pin + §12.8 三態篩選 | ✅ 已出貨（circleMarker→水滴 divIcon+emoji 三態、查核只留 popup、三態篩選 chip） |
| 3c | §12.5 圖卡輪播 banner + 移除 `/cards` | ✅ 已出貨（card-promo 輪播 banner、/cards 302→/、cards 頁刪除、Filament 後台保留） |
| 3d | §12.7 手機右側 IITC 面板 + 卡片 modal | ✅ 已出貨（桌機 chips 手機隱藏、右側 IITC 圖層/狀態 checkbox 面板、卡片 bottom-sheet modal、面板 checkbox wire:ignore 免 poll morph 還原） |

每個 Phase 結束做檢查點:做了什麼/驗證了什麼/剩什麼。視覺項(pin/配色/手機面板)以 chrome-devtools 截圖與實機操作為驗收證據,PHP 可測項照 §12.9 對應斷言。

**v0.3 全數完成（2026-07-08）。** 全套 163 測試綠;線上 hazdar.myurl.tw 桌機/手機實機驗收通過（含手機面板圖層切換跨 wire:poll 存活）。最終 whole-branch review：Ready to merge，無 Critical/Important。
- **重要 pattern**：手機面板的圖層 checkbox 由 vanilla JS（`initChips`）管理 `chip-on` class，卻位於 `wire:poll.10s` scope 內 → 每次 poll 的 morph 會蓋回 blade 預設，故該區塊需 `wire:ignore`（比照 `radar-map.blade.php` 地圖容器）。凡「JS 直接改的 DOM」落在 Livewire poll 元件內都要 `wire:ignore`。
- **v0.3 backlog（非本版 regression，未修）**：`report-list.blade.php:552` 圓餅圖 `borderColor:'#0d1117'` 為 JS 內嵌 hex（配色 grep 掃不到），該圖位於未變動的 `#161b22` 面板上、非本版重染的頁底，屬既有微視覺、非 v0.3 造成；`--color-radar-panel:#161b22` 為死 token；promo/news-counts 快取僅 TTL 不主動 bust（沿用既有可接受模式）。

### 12.11 v0.3 二次回饋(2026-07-08 PM 二輪視覺調整)

修正 §12.2/§12.6 部分安排、補 §12.9 未涵蓋的細節，並修一個與本版無關但驗收時發現的既有 bug。

1. **跑馬燈移入頂帶**:改置頂帶banner 與 nav 之間(取代原底帶位置,比照舊 EOC 大屏 header 佈局);底帶因而收斂為純小字連結 + 燈號的精簡 footer(修正 §12.2 敘述)。
2. **共用 footer / nav 連結**:新增 `partials/footer-links.blade.php`(各分類新聞篇數 + 4 個資料源健康燈號,取自 `Announcement::publishedCountsByCategory()` 與既有 `DataSourceHealth::all()`)與 `partials/nav-page-links.blade.php`(最新消息/通報清單/透明度報告/統計地圖),首頁與 profile/reports/stats/transparency/contact/news 六頁全面共用單一來源;**首頁補回**先前 v0.3 一輪隱藏的 4 個選單連結(修正 §12.6 的「隱藏」安排,改為桌機 nav 顯示)。footer 的健康燈號為**新增**顯示面(§11.1 移除的後台燈號本體不變,此為額外公開曝光,非還原)。
3. **移除 MONITORING pill**:eoc-dashboard 頂部的「MONITORING/TYPHOON-EMERGENCY」浮動 pill 純裝飾、無資訊量,直接移除(隨附 `test_typhoon_badge_when_typhoon_alert_active`/`test_monitoring_badge_without_typhoon` 兩測試一併移除)。
4. **桌機控制項重疊修正**:實測地圖明暗切換按鈕(y=[102,132.8])與 CWA/事件統計浮動卡(原 `top-28`=112)重疊 20.8px;卡片下移至 `top-36`(144px),淨空 11px。
5. **管理員後台入口**:`is_admin` 使用者於 `/profile` 顯示「進入後台管理」連結;Filament admin panel 使用者選單加「回首頁」連回 `route('home')`。
6. **24H 雨量圖表空白 bug 修正**(與本版視覺調整無關,驗收時發現):Chart.js 於容器 0 尺寸(卡片收合、手機 modal 未開)時建圖會畫成空白且不會於容器轉為可見後自動 resize。改用 `ResizeObserver` 延遲初始化:首次量到非 0 尺寸才建圖,之後尺寸變動呼叫 `resize()`;手機 modal 現在也真的顯示圖表(先前 `withChart=false` 完全不畫)。
7. **附帶修復**:`report-list.blade.php` 在 `@php` 區塊內誤用 `{{-- --}}` blade 註解,新版 Blade 不再於 `@php` 內剝除該註解,編譯成非法 PHP;`view:clear` 重編譯後 `/reports` 500。已改 `//` PHP 註解修復,屬既有潛在 bug、與 v0.3 視覺調整無關,驗收 `view:clear` 時意外觸發並當場修掉。

**驗收**:全套 170 測試綠(新增 `AdminLinkTest`/`LoggedInPagesSmokeTest`);線上 hazdar.myurl.tw 實機驗證跑馬燈位置、footer 燈號、pill 移除、桌機重疊消除(JS 量測 overlap_px=0)、雨量圖表(桌機卡片收合→展開、手機 modal 開啟)實際畫出長條圖、admin/非 admin 使用者看到/看不到後台連結。

**已知非本輪處理項**:首頁公開曝光 4 個「test」標題的公告(災情通報/市府與氣象署/居家防災/停班停課,均含 "test" 字樣)目前線上真實存在於 `announcements` 資料表並會被一般訪客看到——這是驗收時發現的**既有生產資料**,非本輪程式改動造成,未經使用者確認不逕行刪除,留待使用者決定是否清除。

### 12.12 v0.3 三次回饋(2026-07-08 PM 三輪視覺調整)

PM 實機測試(含手機截圖)發現 §12.11 交付後仍有 3 處問題,修正如下:

1. **公告/立即回報鈕不應共用實色底帶**:`announcement-banner`/`my-report-status` 元件本來就各自帶半透明卡片樣式(`bg-[#111827]/90`、`backdrop-blur`);§12.11 把它們與立即回報鈕一起包進單一 `bg-[#002923]` 實色帶,等於疊了一層不透明底,不必要地擴大遮蔽地圖的面積。改為:三者各自浮在地圖上(沿用既有半透明樣式);底帶收斂到只剩 footer 一行小字連結+燈號(padding `p-4`→`px-4 py-2`,800px 高視窗下底帶僅 55px)。
2. **手機頂帶 nav 溢出**:登入後 我的回報/頭像/登出 與其餘項目一起擠進手機寬度會橫向溢出(PM 截圖確認)。改為:桌機(`lg` 以上)維持橫排;手機改漢堡按鈕+展開面板,面板含完整選單(含 §12.11 新增卻被 `hidden lg:flex` 誤擋在手機看不到的 4 個頁面連結)。
3. **次要頁面未共用首頁 head nav**:profile/reports/news/stats/transparency/contact 先前是獨立一份「◎ Hazdar」文字版 header,與首頁 banner+跑馬燈版本不一致。抽出 `partials/site-header.blade.php`(banner+跑馬燈+選單+手機漢堡)單一來源,首頁與六個次要頁面全部共用;`nav.blade.php` 收斂為薄包裝。
4. **跑馬燈長訊息改左滾動**:原本用 `truncate` 把超出容器寬度的長訊息(常見於 NCDR 高溫/豪雨警戒長文案)直接裁掉看不到後半段。改為 JS 量測 `scrollWidth` 是否超出容器,超出才加 `.radar-ticker-marquee` 向左捲動播完整句;短訊息維持原本置中靜態顯示。
5. **附帶修復**:跑馬燈邏輯抽成獨立 `livewire/site-ticker.blade.php` 元件(30s 快取),不再綁在整個地圖元件(`radar-map`)上——次要頁面沒有地圖,原本永遠拿不到跑馬燈資料。同時發現 `initTicker()` 原本巢狀在 `initRadar()` 內,只有 `#radar-map` 存在(首頁)才會執行,已改為獨立於地圖呼叫。

**驗收**:全套 172 測試綠(新增 `test_mobile_has_hamburger_menu_with_full_link_set`/`test_secondary_pages_share_home_header_with_banner_and_ticker`);線上桌機/手機實機驗證:公告/回報鈕浮動不遮地圖、手機漢堡選單展開正確、`/reports` 等次要頁面顯示 banner+跑馬燈與首頁一致、長 NCDR 警戒訊息正確向左捲動播出完整文字且被 `overflow:hidden` 正確裁切在跑馬燈膠囊範圍內(以強制動畫暫停截圖驗證,排除截到 400ms opacity 過場瞬間的偽陽性)。
