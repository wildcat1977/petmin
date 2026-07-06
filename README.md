# CivicShield 全民韌性災害回報系統

> 颱風／豪雨災時，讓民眾以最低摩擦回報四類災情（淹水、道路中斷、停電、結構受損），
> 經自動佐證與官方核實分層後，即時呈現在民眾地圖與 EOC 戰情室大屏上，
> 並整合 CWA / NCDR / data.taipei 官方資料，形成台北市單一情勢圖。

本專案 fork 自 [ratdar（見鼠地圖）](https://github.com/wildcat1977/ratdar)，
沿用其回報 pipeline、Socialite 登入、Leaflet 地圖基底與 Claude Vision 審核，
domain 由「鼠患通報」改造為「災害回報」。完整技術規格見 [docs/civicshield-spec.md](docs/civicshield-spec.md)。

## 技術堆疊

| 領域       | 套件 / 版本                                            |
| ---------- | ------------------------------------------------------ |
| 後端框架   | Laravel 13 / PHP 8.5                                   |
| 前端互動   | Livewire 4（SFC 格式）                                 |
| CSS        | Tailwind CSS 4 + Vite 8                                |
| 地圖       | Leaflet 1.9 + leaflet-control-geocoder                 |
| 認證       | Laravel Socialite 5（Google OAuth + LINE LIFF）        |
| 後台       | Filament 5                                             |
| 佇列/排程  | Laravel Horizon 5（Redis）                             |
| 資料庫     | PostgreSQL（`.env` 中 `DB_CONNECTION=pgsql`）          |
| 圖片處理   | intervention/image 4（GdDriver）                       |
| AI 審核    | Claude Vision API（Guzzle 呼叫，照片內容 + 分類吻合判斷）|

## 核心設計

- **佐證機制（非 AI）**：回報寫入以 `geohash7`（precision 7 ≈ 150m 網格）分桶。同一網格 + 同分類、
  6 小時滑動窗內 ≥ 3 個不同帳號 → 該群自動升級 `corroborated`。純 deterministic SQL，見
  `app/Jobs/EvaluateCorroboration.php`。
- **兩個正交狀態**：`status`（內容適切性審核：pending/approved/rejected）與
  `verification_level`（災情核實：unverified/corroborated/official）分離。內容審核 ≠ 災情核實。
- **點位 API（取代前端全量模式）**：`GET /api/map/reports?bbox=&zoom=&categories=` 走 server-side
  bbox query；高 zoom 回個別點位、低 zoom 依 geohash 聚合，回應有筆數/payload 上限並快取。
- **外部整合層**：scheduled command 拉外部資料 → 落地（表或 Redis）→ 前端只讀落地資料。
  任一 fetch 失敗記 log + 反映在 dashboard footer 健康燈（黃=延遲、紅=中斷），禁止默默跳過。
- **寫入即回應**：回報寫入不含 AI 審核與地址反查（queue 後補），外部 API 全掛時回報仍可提交、地圖仍可看。

## 快速啟動

```bash
cd /var/www/html/civic_shield

# 1. 環境變數
cp .env.example .env   # 若無 .env.example 可參考本 README「環境變數說明」
php artisan key:generate
# 必填：DB_*
# 選填：GOOGLE_* / LINE_* / LIFF_ID（災時主入口預期是 LINE）
# 選填：CLAUDE_API_KEY（未填時照片 fail-open 直接通過，留待人工）
# 選填：CWA_API_KEY（opendata.cwa.gov.tw 授權碼；未填時該源亮紅燈但不影響回報）

# 2. 資料庫（含示範災情 seed，僅 local 環境產生）
php artisan migrate --seed

# 3. Filament 後台帳號
php artisan make:filament-user

# 4. 前端資產
npm install
npm run build                  # 或 npm run dev（本機開發）

# 5. 啟動
php artisan serve
php artisan horizon            # 另一 terminal（處理 AI 審核與佐證 queue）
```

開啟：

- 民眾地圖    <http://localhost:8000>
- EOC 戰情室  <http://localhost:8000/dashboard>
- 回報清單    <http://localhost:8000/reports>
- 透明度報告  <http://localhost:8000/transparency>
- 後台        <http://localhost:8000/admin>
- 佇列監控    <http://localhost:8000/horizon>

## 目錄總覽

```
app/
├── Console/Commands/
│   ├── FetchCwaObservations.php      # CWA 測站即時觀測 → weather_observations + Redis
│   ├── FetchNcdrAlerts.php           # NCDR CAP feed → external_alerts（涵蓋台北市判定）
│   ├── FetchRadarEcho.php            # CWA 雷達回波整合圖 → 後端 proxy 快取圖檔
│   ├── SyncShelters.php              # data.taipei 收容所清單同步（is_open 不覆寫）
│   ├── PruneWeatherObservations.php  # 觀測歷史保留 7 天
│   └── BackfillReportAddresses.php   # 對既有回報批次補跑 reverse geocode
├── Filament/Resources/
│   ├── Reports/                      # 災情回報後台（審核 + 核實 + 標記已排除）
│   ├── Shelters/                     # 收容所後台（災時開設狀態即時切換）
│   ├── DashboardResources/           # 物資看板後台（官方手動維護數量）
│   ├── Contacts/                     # 聯絡訊息後台
│   └── Users/
├── Http/Controllers/
│   ├── Api/MapPointsController.php    # 點位 API（bbox query + geohash 聚合）
│   └── Auth/SocialiteController.php   # Google / LINE OAuth + LINE LIFF callback
├── Jobs/
│   ├── EvaluateCorroboration.php      # 佐證判定（deterministic SQL，queue 執行）
│   └── ModerateReportImage.php        # AI 照片審核（queue 後補，不阻塞寫入）
├── Models/
│   ├── Report.php                     # category / verification_level / geohash7 hook
│   ├── WeatherObservation.php · ExternalAlert.php · Shelter.php · DashboardResource.php
│   └── User.php · Contact.php
├── Services/
│   ├── DataSourceHealth.php           # 外部源健康狀態燈（green/yellow/red）
│   ├── ImageModerationService.php     # Claude Vision 審核（內容 + 分類吻合）
│   └── GmailMailer.php                # Gmail API 郵件發送（聯絡回覆用）
└── Support/Geohash.php                # 純 PHP geohash 編碼／解碼（無外部依賴）

resources/
├── css/app.css                        # Tailwind 4 + 分類 chips + Leaflet 主題
├── js/app.js                          # Leaflet / bbox 抓點 / 聚合 / 圖層切換 / LIFF
└── views/
    ├── pages/radar.blade.php          # 民眾地圖頁（分類篩選 chips）
    ├── pages/dashboard.blade.php       # EOC 戰情室大屏（雨量趨勢 Chart.js）
    ├── livewire/radar-map.blade.php    # 地圖 SFC（點位改由前端向 bbox API 取）
    ├── livewire/report-form.blade.php  # 回報表單（四類 + 淹水深度 + 照片選填）
    └── livewire/eoc-dashboard.blade.php# 戰情室資料 SFC（統計/健康/物資）

config/
├── civicshield.php                     # 地圖、反濫用、佐證、點位 API、外部源設定
└── services.php                        # Google / LINE / LIFF / Claude 設定

public/geo/taipei-districts.json        # 台北市 12 行政區界（EOC 疊層用）
routes/console.php                      # 外部整合排程（CWA/NCDR/雷達/收容所）
```

## 常用 Artisan 指令

```bash
# 外部資料源可獨立手動執行（排程之外）
php artisan civicshield:fetch-cwa-observations
php artisan civicshield:fetch-ncdr-alerts
php artisan civicshield:fetch-radar-echo
php artisan civicshield:sync-shelters

# 對缺少地址的回報批次補跑 reverse geocode（Nominatim，1 req/sec）
php artisan reports:backfill-addresses
```

## 環境變數說明

| 變數                          | 說明                                                 | 必填 |
| ----------------------------- | ---------------------------------------------------- | ---- |
| `DB_CONNECTION`               | `pgsql`（PostgreSQL）                                | ✓    |
| `GOOGLE_CLIENT_ID/SECRET`     | Google OAuth（新網域需在 console 補 redirect URI）   |      |
| `LINE_CLIENT_ID/SECRET`       | LINE Login Channel                                   |      |
| `LIFF_ID`                     | LINE LIFF App ID（in-app 瀏覽器自動登入用）          |      |
| `CLAUDE_API_KEY`              | Anthropic API Key（未填時照片 fail-open 通過）       |      |
| `CWA_API_KEY`                 | CWA 開放資料平台授權碼（opendata.cwa.gov.tw）        |      |
| `TAIPEI_SHELTER_RESOURCE_ID`  | data.taipei 收容所 dataset rid（未填則不同步）       |      |
| `FLOOD_POTENTIAL_WMTS_URL`    | 淹水潛勢 WMTS 服務 URL（未填則前端不掛此圖層）        |      |
| `RADAR_AUTO_APPROVE`          | `true` 讓無照片回報跳過人工審核直接公開               |      |
| `CIVICSHIELD_REPORT_COOLDOWN` | 每帳號回報間隔秒數（預設 60）                         |      |
| `CIVICSHIELD_REPORT_HOURLY_LIMIT` | 每帳號每小時回報上限（預設 10）                  |      |

## 文件索引

- [docs/civicshield-spec.md](docs/civicshield-spec.md) — 技術規格與驗收標準
- [docs/architecture.md](docs/architecture.md) — 系統架構與關鍵流程
- [docs/database-schema.md](docs/database-schema.md) — 資料庫結構
- [docs/features.md](docs/features.md) — 功能與 UI 動線
- [docs/deployment.md](docs/deployment.md) — 部署與第三方設定
- [docs/development.md](docs/development.md) — 本地開發
