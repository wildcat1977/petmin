# 系統架構

```
┌──────────────────────────────────────────────────────────────┐
│                        Browser (Mobile-first)                │
│  ┌──────────────┐  ┌─────────────────┐  ┌────────────────┐   │
│  │ Leaflet Map  │  │ Livewire Modals │  │ Geolocation API│   │
│  │ + bbox fetch │  │ (Report / Auth) │  │ + Camera input │   │
│  └──────┬───────┘  └────────┬────────┘  └────────┬───────┘   │
└─────────┼───────────────────┼────────────────────┼───────────┘
          │ fetch /api/map/*   │ Livewire (XHR)    │ navigator.*
          ▼                    ▼                    ▼
┌──────────────────────────────────────────────────────────────┐
│                     Laravel 13 Application                   │
│  ┌─────────────┐ ┌──────────────┐ ┌─────────────────────┐    │
│  │ MapPoints   │ │ Livewire SFC │ │ Eloquent (Report)   │    │
│  │ Controller  │ │ radar-map /  │ │ + geohash7 hook →   │    │
│  │ (bbox API)  │ │ report-form /│ │   EvaluateCorrob... │    │
│  │             │ │ eoc-dashboard│ │   (queued)          │    │
│  └─────────────┘ └──────────────┘ └─────────────────────┘    │
│  ┌─────────────┐ ┌──────────────┐ ┌─────────────────────┐    │
│  │ Horizon     │ │ Filament v5  │ │ Scheduled fetch cmds│    │
│  │ (AI審核/佐證)│ │ /admin Panel │ │ CWA/NCDR/雷達/收容所 │    │
│  └─────────────┘ └──────────────┘ └──────────┬──────────┘    │
└────────┬────────────────────┬────────────────┼──────────────┘
         ▼                    ▼                 ▼
   ┌──────────┐        ┌────────────┐   ┌──────────────────┐
   │  Redis   │        │ PostgreSQL │   │ 外部官方資料源    │
   │ Queue /  │        │            │   │ CWA / NCDR /     │
   │ Cache /  │        │            │   │ data.taipei      │
   │ 最新觀測 │        │            │   │（只由排程存取）   │
   └──────────┘        └────────────┘   └──────────────────┘
```

核心原則（spec §8 韌性要求）：**回報寫入路徑與外部整合層零共用依賴**。
讀取端（民眾地圖、dashboard）全走落地資料 + cache；CWA/NCDR 全掛時整站仍可回報、可看圖。

## 關鍵流程

### 1. 進入民眾地圖 → 看到災情點位
1. `routes/web.php` `/` 渲染 `pages/radar.blade.php`，內含 `<livewire:radar-map />`
2. `radar-map` 只輸出地圖容器與 `data-reports-url`（點位不再序列化進 HTML）
3. `resources/js/app.js` 在 `livewire:initialized` 初始化 Leaflet（底圖為自架 Protomaps 向量圖磚，
   見 `docs/basemap.md`），依當前 viewport 向
   `GET /api/map/reports?bbox=&zoom=&categories=` 抓點
4. 地圖平移／縮放（`moveend`/`zoomend`）觸發 debounce 後重新抓取；前一個未完成的請求以 `AbortController` 取消

### 2. 點位 API（`MapPointsController`，取代 ratdar 前端全量模式）
1. 驗證 bbox（west,south,east,north）與 zoom
2. `zoom ≥ cluster_below_zoom(15)` → 回個別點位（`Report::visible()->unresolved()`，
   筆數上限 `hazdar.map.max_points`，超過標記 `truncated`）
3. `zoom` 低 → 依 `substr(geohash7, 1, precision)`（precision 隨 zoom 4~6）分桶聚合，
   回 `{center, count, dominant_category}`
4. 回應以 bbox（3 位小數）+ zoom + filter 為 key 快取 `hazdar.map.cache_seconds` 秒
5. 照片 URL 只在 `status = approved` 時輸出（`Report::publicImageUrl()`），審核前不公開；
   彈窗改用 `img_md`（長邊 1280 的縮圖），還沒產生時排入 `GenerateReportPhotoVariants` 並先用原圖（`docs/basemap.md`）

### 3. 回報送出（`ReportForm::submit()`）
1. 反濫用檢查：帳號封禁 → cooldown → 每小時上限（`config('hazdar.report_*')`）
2. 驗證欄位（四類分類必選、淹水深度僅 flood、照片選填）
3. 有照片 → 重新編碼 JPEG 存至 `storage/app/public/reports/`（不含 AI 審核）
4. 寫入 `reports`（有照片 → `pending`；無照片 → 依 `auto_approve`）
5. Model `creating` hook 由 lat/lng 推導 `geohash7`；`created` hook dispatch
   `EvaluateCorroboration`（`afterCommit`）
6. 有照片 → dispatch `ModerateReportImage`（queue 後補）
7. dispatch `report-submitted` → JS 重新向 bbox API 抓點

### 4. 佐證判定（`EvaluateCorroboration` Job，純 SQL）
1. 撈同 `geohash7` + 同 `category`、近 6 小時滑動窗、內容審核未退件的回報
2. `distinct user ≥ 3` → 該群所有 `unverified` 更新為 `corroborated`
3. `official`（官方核實）只能由 Filament 後台人工標記，此處不觸碰

### 5. 外部資料整合（scheduled → 落地 → 前端只讀）
1. `routes/console.php` 排程各 fetch command（CWA 5min、NCDR 5min、雷達 10min、收容所 daily）
2. 每支 command 有 10s timeout、獨立 try/catch；成功 → `DataSourceHealth::recordSuccess()`，
   失敗 → log + `recordFailure()`，不 retry storm（下一輪自然重試）
3. 落地目標：`weather_observations` / `external_alerts` / `shelters` 表，
   即時值（CWA 最新觀測、雷達圖檔）走 Redis / storage
4. EOC dashboard footer 讀 `DataSourceHealth::all()` 顯示各源狀態燈

### 6. EOC 戰情室（`eoc-dashboard`，公開唯讀）
- `wire:poll.10s` 刷新統計卡片、NCDR 燈號、footer 健康指標
- 24H 雨量趨勢由 Chart.js 渲染（`weather_observations` 每小時 rain_1h 最大值）
- 中央地圖沿用 `radar-map`（`show-districts=true` 開啟行政區界疊層）
- footer 為**真實系統健康指標**（各源狀態燈、queue 深度、今日回報數/官方核實數），
  不做 mockup 上的假 mesh 數據（spec §2 設計修正）

## 為何用 Livewire 4 而非 3
Filament 5 強制 livewire/livewire ^4.1。前台頁面沿用 ratdar 的 single-file component (SFC) 寫法，
PHP class 與 Blade 同檔（`resources/views/livewire/*.blade.php` 內以 `new class extends Component`）。

## 為何點位改走 server-side bbox API
ratdar 原本把 approved 點位（限 5000）全部送前端再以 Haversine 計算。災時回報量可達 5 萬筆以上，
前端全量會爆（spec §7.2）。改為 server-side bbox query + geohash 聚合後，任一 viewport 回應
payload ≤ 300KB、p95 ≤ 800ms（驗收 §1-3）。geohash 分桶足以支撐 MVP 量級；再上去的 scale-up
path 是 PostGIS `ST_ClusterDBSCAN` + GiST index（spec §4.4），lat/lng 欄位刻意保留裸值不被 accessor 包死。
