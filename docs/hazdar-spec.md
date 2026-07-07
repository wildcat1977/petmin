# Hazdar 全民風災回報系統 — 技術規格 v0.2

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
- 頂部警戒跑馬燈、footer 健康指標照舊掛在首頁。
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

- 新表 `report_categories`:`slug`(unique,**建立後唯讀**,程式與 reports.category 以 slug 為鍵)、`name`、`color`(hex)、`sort_order`、`is_active`、`extra_field` jsonb nullable `{label, unit, min, max}`(**單一選填數值欄位**)。
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

| Phase | 內容 | 備註 |
|---|---|---|
| 2a | §11.2 雷達校正 + §11.3 免責彈窗 | 小而獨立,先出貨 |
| 2b | §11.7 分類後台化 + §11.8 三態 | schema 先行,地圖/表單/統計跟進 |
| 2c | §11.4 news + §11.5 資訊彙整 + §11.6 圖卡 | 內容系統,互相獨立可並行 |
| 2d | §11.1 首頁整合 | 動最大,吃 2b/2c 產出的卡片 |

每個 Phase 結束做檢查點:做了什麼/驗證了什麼/剩什麼。
