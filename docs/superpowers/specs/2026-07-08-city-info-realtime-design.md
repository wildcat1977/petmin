# 交通與清運資訊即時化 + 獨立頁面 — 設計（spec §11.5 P2 實現）

**日期**：2026-07-08
**狀態**：設計已與 PM 對齊，待寫實作計畫（writing-plans）

## Goal

把首頁「交通與清運資訊彙整」卡從**手動維護的連結 + 狀態文字**，升級為**真實抓取並解析的即時資料**；並新增一個**獨立頁面** `/info` 呈現完整資訊。同時把 head nav 的「透明度報告」「統計地圖」連結移除（route 保留、僅去連結），換成此頁面連結。

## Architecture

沿用本專案既有整合層慣例：**排程 command → 抓外部來源 → 落地 DB → 前端只讀落地資料**（見 `hazdar:fetch-ncdr-alerts` / `hazdar:sync-shelters` / `hazdar:fetch-cwa-observations`）。每個來源接 `App\Services\DataSourceHealth` 健康燈、參數集中在 `config/hazdar.php` 的 `sources`。落地一律純陣列/scalar，**禁止快取 Eloquent 物件/Collection**（既有生產事故教訓）。前端永不直接打外部 API。

## Tech Stack

Laravel 13 / PHP 8.4 / Livewire 4 / Filament 5 / PostgreSQL / Tailwind 4。新增外部來源：TDX 運輸資料流通服務（OAuth2）、臺北市水利處 HEO 開放資料、NCDR CAP feed（既有）。

## Global Constraints

- 直接在 `main` = 生產（hazdar.puma.taipei）作業；每步 `php artisan test --compact` 必綠、`vendor/bin/pint --dirty`、`npm run build`（新 utility class）、`php artisan view:clear`、線上桌機+手機實機驗證。
- 落地資料只存純陣列/scalar，讀取 `Model::hydrate()` 或直接查表；不 `Cache::put` Eloquent 物件。
- 新 class 後 `composer dump-autoload -o`。
- 每個外部來源失敗必反映在 `DataSourceHealth`，不得默默跳過（驗收 §1-5）。
- `config` 目前**不快取**（改 env 即時生效）；若日後 `config:cache`，改 env 需重建。
- 不動 `resources/views/partials/gtag.blade.php`（使用者既有未提交變更）。

---

## 1. 資料來源（皆已實測，2026-07-08 於生產機驗證）

| 區塊 | 資料 | 來源 / 端點 | 認證 | 即時性 | 落地欄位重點 |
|---|---|---|---|---|---|
| 大眾交通 | 捷運 | TDX `GET /api/basic/v2/Rail/Metro/Alert/TRTC?$format=JSON` | OAuth2（免費，PM 註冊） | 事件 | `Status`(0全停/1正常/2異常), `Scope.Lines[].LineName`, `Reason`, `Effect`, `StartTime/EndTime` |
| | 貓空纜車 | 同上 `.../Alert/TRTCMG` | 同上 | 事件 | 同上（單線，`Status` 即答案） |
| | 公車 | TDX `GET /api/basic/v2/Bus/Alert/City/Taipei?$format=JSON` | 同上 | 事件 | `Status`(0/1/2), `Cause`(6=氣候), `Effect`(4=停駛/3=減班), `Scope`, `Title` |
| 水門與抽水站 | 抽水站即時 | HEO `GET https://heopublic.gov.taipei/taipei-heo-api/openapi/pumb/latest` | 無 | 每小時/事件 | `stn_id, stn_name, lon, lat, obs_time, inner_value, outer_value, pumb_num, door_num, pumb_status(停止/運轉中), door_status(閘門開啟/關閉/null), max_allowable_water_level` |
| | 疏散門（靜態位置） | data.taipei rid `47cffd30-3527-45af-b709-6f76772e3cfb`（既有 `scope=resourceAquire` pattern） | 無 | 年更 | `riverside_park, name, description, longitude, latitude`（已 WGS84，36 座） |
| 停班停課 | 天然災害停班停課 | NCDR CAP `GET https://alerts.ncdr.nat.gov.tw/RssAtomFeed.ashx?AlertType=33` | 無 | 事件 | Atom+CAP：`title`(停班停課), `cap:effective/expires`, `cap:areaDesc`, summary 決定文字 |
| 避難收容所 | 已完成 | `shelters` 表（既有） | — | — | 開設數（`is_open=true`） |

**已排除/降級（實測結果）**
- **疏散門即時狀態**（`wic.heo.taipei/OpenData/API/Evacuate/Get`）：即使從生產 TW 機也 **HTTP 000 / 20s timeout**（該主機防火牆封鎖，非單純 IP 白名單）。**不做即時狀態，只放靜態位置**。
- **spec §11.5 誤標**：`2bbfb30e` 其實是「抽水站」dataset 且為 `openapi` 型，用 `scope=resourceAquire` 回空陣列；真資料在 HEO `pumb/latest`。此處更正。
- **清運（垃圾車停收 / 災後集中點）**：無結構化 API，**本階段不做**，維持手動 `InfoLink`。

**TDX 實測補充（2026-07-08 以 PM 憑證於生產機驗證，token len 1031、三端點皆 HTTP 200）**
- **正常狀態不是空陣列**：捷運/纜車正常時回**單一 sentinel** `{"AlertID":"0","Status":1,"Title":"正常營運","Scope":{空}}`。解析規則：整段只有 `AlertID="0"` 或全部 `Status=1` → 顯示「全線正常」；有 `AlertID≠"0"` 且 `Status∈{0,2}`、`Scope.Lines/Routes` 有值 → 才是真的異常。
- **公車 feed 很吵**：實測 39 筆多為例行（寒假調整、活動改道，`Cause=11`）。災害卡片**只取災害相關**：`Cause=6(氣候)` 或 `Effect∈{4停駛,7重大延遲}`，其餘不顯示；平時（無災害相關）顯示「公車 正常」。
- 欄位確認：`Status`(0/1/2)、`Cause`、`Effect`、`Scope.Routes[].RouteName.Zh_tw`、`Scope.Lines[].LineName`、`Title`、`Description`、`StartTime/EndTime`、`PublishTime`。

## 2. 資料模型

**新增 `pump_stations`（抽水站即時，`hazdar:fetch-pump-status` 每 10 分鐘）**
- `id, stn_id(unique), name, latitude, longitude, pump_num, gate_num, pumb_status, door_status(nullable), inner_value(nullable), outer_value(nullable), max_level(nullable), observed_at(nullable), timestamps`
- upsert by `stn_id`。座標即時 feed 已含（WGS84），不需另存靜態表。

**新增 `floodgates`（疏散門靜態，`hazdar:sync-floodgates` 日更或隨抽水站排程）**
- `id, external_key(unique), name, description, latitude, longitude, timestamps`
- upsert by `external_key`（`riverside_park + name`）。純位置，無狀態欄。

**新增 `transit_alerts`（TDX，`hazdar:fetch-transit-alerts` 每 5 分鐘）**
- `id, system(metro|gondola|bus), identifier(nullable), scope_name(nullable, 線/路名), status(smallint 0/1/2), cause(nullable), effect(nullable), title(nullable), description(nullable), effective_at(nullable), expires_at(nullable), timestamps`
- 每輪先清該來源舊資料再落地（alert 是全量快照，非累積）；或以 identifier upsert + 清掉本輪未見者。實作計畫決定，預設「truncate-by-system 後重灌」最簡。

**復用 `external_alerts`（停班停課）**
- 擴充 `hazdar:fetch-ncdr-alerts` 多抓 `AlertType=33`，落地時 `source='NCDR'`、`alert_type='停班停課'`、`covers_taipei` 以 `areaDesc` 是否含臺北市判定、`headline` 存決定文字、`effective_at/expires_at` 存 CAP 時間。前端讀 `ExternalAlert::where('alert_type','停班停課')->where('covers_taipei',true)->where('expires_at','>',now())`。

## 3. Fetch commands + 排程（routes/console.php）

| Command | 排程 | 說明 |
|---|---|---|
| `hazdar:fetch-pump-status` | `everyTenMinutes` | HEO pump/latest → `pump_stations` upsert；健康燈 `pump_status` |
| `hazdar:sync-floodgates` | `dailyAt('05:20')` | data.taipei 疏散門 → `floodgates` upsert；健康燈 `floodgates` |
| `hazdar:fetch-transit-alerts` | `everyFiveMinutes` | TDX 三端點 → `transit_alerts`；健康燈 `transit`；**無憑證→跳過並標記 disabled，不報 error** |
| `hazdar:fetch-ncdr-alerts`（擴充） | `everyFiveMinutes`（既有） | 追加 AlertType=33 → `external_alerts`（`alert_type=停班停課`） |

TDX token：`POST https://tdx.transportdata.tw/auth/realms/TDXConnect/protocol/openid-connect/token`（`grant_type=client_credentials`），回 `access_token`（`expires_in≈86400`）。**token 快取於 app cache（純字串）24h**；呼叫 API 帶 `Authorization: Bearer`；收 401 則清 token 重取一次。

## 4. config/hazdar.php 追加

```php
'sources' => [
    // ...既有...
    'pump' => [
        'url' => env('HAZDAR_PUMP_URL', 'https://heopublic.gov.taipei/taipei-heo-api/openapi/pumb/latest'),
        'interval_minutes' => 10,
    ],
    'floodgates' => [
        'resource_id' => env('TAIPEI_FLOODGATE_RESOURCE_ID', '47cffd30-3527-45af-b709-6f76772e3cfb'),
    ],
    'transit' => [
        'tdx_client_id' => env('TDX_CLIENT_ID'),        // PM 註冊後填
        'tdx_client_secret' => env('TDX_CLIENT_SECRET'),
        'token_url' => env('TDX_TOKEN_URL', 'https://tdx.transportdata.tw/auth/realms/TDXConnect/protocol/openid-connect/token'),
        'base_url' => env('TDX_BASE_URL', 'https://tdx.transportdata.tw/api/basic'),
        'endpoints' => [
            'metro' => '/v2/Rail/Metro/Alert/TRTC',
            'gondola' => '/v2/Rail/Metro/Alert/TRTCMG',
            'bus' => '/v2/Bus/Alert/City/Taipei',
        ],
    ],
    'ncdr_closure_alert_type' => env('NCDR_CLOSURE_ALERT_TYPE', 33),
],
```
`transit` 是否啟用＝`tdx_client_id && tdx_client_secret` 皆有值。

## 5. 呈現層

**獨立頁面 `/info`**（route name `city-info`，view `resources/views/pages/city-info.blade.php`，套 `layouts.app` + 共用 `site-header`/`footer-links`）。由上而下：
1. **停班停課**：有有效且涵蓋台北的警戒 → 醒目紅/橘 banner（決定文字 + 生效時間）；無 → 淡色「目前正常上班上課」。
2. **大眾交通**：捷運各線 + 貓空纜車 + 公車，每列狀態徽章（`status` 映射：1→正常綠、2→調整橘、0→停駛紅；無憑證整段不顯示）。附 `Reason`（颱風）。
3. **水門與抽水站**：抽水站列（運轉中黃/停止灰、閘門開/關、內外水位）；疏散門位置（連地圖/清單，無狀態）。顯示抽水站 `observed_at`。
4. **清運與環保局**：維持手動 `InfoLink`（deferred，後台維護）。
5. **避難收容所**：開設數 + 連地圖。

**首頁卡**（改 `eoc-dashboard` 右欄現有「交通與清運資訊」卡 + 手機面板 modal）：parsed 摘要（捷運整體狀態、抽水站 N 運轉中、停班停課狀態）＋「查看完整交通與清運資訊 →」連 `/info`。

**Nav**（`partials/nav-page-links.blade.php`）：移除 `route('transparency')`、`route('stats')` 連結（route 定義保留、可 URL 直達），新增 `route('city-info')`「交通與清運資訊」。

**狀態徽章映射**（沿用現有 mockup 配色）：正常=emerald、調整/關閉=amber、停駛=rose、運轉中=yellow、待命/未知=slate。

## 6. 錯誤處理與新鮮度

- **空=正常**：alert 陣列空、無停班停課 → 顯示正常預設，不是「無資料」。
- 每來源 fetch 成功/失敗都寫 `DataSourceHealth`；失敗時頁面/卡顯示**最後已知落地資料** + 「資料可能延遲（更新於 <observed_at>）」，不阻斷 render。
- TDX token 快取、401 重取一次；`transit` 未設憑證 → command 記 `DataSourceHealth` 為 disabled/skip、前端整段隱藏、**不報 error**。
- 停班停課自由文字：解析「是否涵蓋臺北市」與決定文字；解析不到明確決定時，原文照顯不硬猜。

## 7. 測試策略

- 各 fetch command：`Http::fake` 帶真實樣本回應 → 斷言落地筆數/欄位解析/`DataSourceHealth` 狀態；失敗回應 → 斷言健康燈紅且不 throw（比照 `FetchNcdrAlertsTest`）。
- `fetch-transit-alerts`：有憑證+fake token → 落地；**無憑證 → 跳過、健康燈 disabled、不建立資料、不報錯**。
- 停班停課解析：涵蓋台北的 CAP entry 進 `external_alerts` 且 `covers_taipei=true`；未涵蓋台北不顯示；過期不顯示。
- `/info` 頁面 render：有資料 + 全正常空狀態 兩情境皆 200；無 TDX 憑證時交通段不出現且不報錯。
- 首頁卡摘要數字正確（如「抽水站 N 運轉中」）。
- Nav：`/` 與次要頁不再出現 `route('transparency')`/`route('stats')` 連結、出現 `route('city-info')`（比照既有 `HomeChromeTest` nav 斷言）。
- 快取污染：測試前 `Cache::forget` 相關 key（比照既有）。

## 8. PM 待辦（阻擋 transit 上線的唯一依賴）

註冊免費 TDX 帳號並產生金鑰：
1. 到 https://tdx.transportdata.tw/register 註冊（email 驗證 + 審核；學術/一般皆可）。
2. 登入後於「會員中心 → API 金鑰」建立一組 `client_id` / `client_secret`。
3. 填入生產 `.env`：`TDX_CLIENT_ID="..."`、`TDX_CLIENT_SECRET="..."`（值含特殊字元記得加引號）。
4. 交通段即自動啟用（下一輪 5 分鐘排程落地）。

## 9. 分期（實作計畫據此切）

- **Phase 1（無憑證依賴，先上）**：抽水站即時 + 疏散門位置 + 停班停課（擴充 NCDR）+ `/info` 頁面骨架 + 首頁卡改版 + nav 換連結。
- **Phase 2（PM 註冊 TDX 後）**：`transit_alerts` + TDX fetch command + 交通段 UI（credential-gated，程式先建好，憑證到位即亮）。
- **不做**：垃圾車停收、災後集中點（無 API，維持手動 InfoLink）。

## 驗收準則

- 172+ 既有測試維持綠，新增各來源解析/頁面/nav 測試綠。
- 生產實機：`/info` 桌機+手機顯示真實抽水站狀態、停班停課正確（事件時醒目/平時正常）、疏散門位置上圖；nav 已換；首頁卡顯示 parsed 摘要而非純連結；任一來源掛掉頁面不崩、健康燈正確。
- transit：填入 TDX 憑證後交通段亮、顯示各線狀態；未填時整段隱藏不報錯。
