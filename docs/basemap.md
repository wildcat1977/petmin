# 底圖與照片縮圖

2026-10-01 地圖底圖從 MapTiler（`config/hazdar.php` 的 `tile_*`，key 直接印在網頁原始碼）與
直連 `tile.openstreetmap.org` 改為自架的 Protomaps 向量圖磚，同時為回報照片加上縮圖。兩者都從
洋流好店（friendly-store-map）移植。圖磚服務的架設、更新與完整設計寫在洋流好店的
`docs/basemap-spec.md`（§3 圖磚服務、§9 照片縮圖）與 `docs/deployment.md`「自架底圖」一節，這裡只記 hazdar 自己的部分。

## 為什麼換

- MapTiler 一張 512px 圖磚算 4 次請求，條款又禁止代理與伺服器端快取；Flex 方案只到 2026-10-12。
- hazdar 把 MapTiler key 直接印在網頁原始碼。
- 統計、透明度頁與後台直連 OSM 官方圖磚伺服器：不符 OSM 使用政策，會被擋成 403（gotcha 後台 2026-09-24 中招）。
- 暗色模式用 CSS `filter: invert(…)` 反色整個 `tilePane`，連疊在同一個 pane 的淹水潛勢圖層也一起反色。

## 圖磚服務（共用，不在這個 repo）

- `https://tiles.puma.taipei/styles/light.json`、`…/dark.json`：Protomaps 官方 light / dark 風格，內含圖磚、字型、圖示網址
- 資料只擷取台灣本島＋澎湖、蘭嶼、綠島（不含金馬）
- 服務、Apache、Cloudflare 快取規則、每月更新腳本都在洋流好店 repo 的 `deploy/basemap`、`scripts/basemap`

## 前端

MapLibre 以 `@maplibre/maplibre-gl-leaflet` 嵌成 Leaflet 的底圖圖層；點位、聚合、收容所、雷達回波、
淹水潛勢、行政區界等 Leaflet 圖層都沒動。

- **設定**：`config('hazdar.map.basemap_styles')`（`light` / `dark`，env `HAZDAR_BASEMAP_LIGHT_STYLE_URL`、
  `HAZDAR_BASEMAP_DARK_STYLE_URL`）、`basemap_attribution`、`max_zoom`（預設 19）。
  `layouts/app.blade.php` 注入 `window.__HAZDAR_BASEMAP_CONFIG__`，所有前台地圖經 `resources/js/basemap.js` 取用。
- **各地圖**：

  | 地圖 | 位置 | 風格 |
  |---|---|---|
  | 民眾主地圖 | `resources/js/app.js` `initRadar()` | 依使用者選的主題（預設暗色），切換時 `setStyle` |
  | 回報表單選點小地圖 | `resources/js/app.js` `initPinMap()` | light |
  | 通報列表的地圖彈窗 | `livewire/report-list.blade.php` | dark |
  | 統計頁、透明度頁 | `pages/stats.blade.php`、`pages/transparency.blade.php` | dark |
  | 後台選點地圖 | `filament/components/map-picker.blade.php`（CDN UMD） | light |

- **maplibre-gl 釘死 5.24.0**：6.x 只出 ESM，打包後 worker 載不到（底圖全灰），後台 CDN 也沒有 UMD 檔。
  前後台必須同一版本，`BasemapTest` 會比對實際安裝版本與 CDN 網址。
- **疊放順序**：MapLibre 容器在 `tilePane` 且沒有 z-index；淹水潛勢 `L.tileLayer` 同在 `tilePane`、z-index 1，
  所以仍疊在底圖上；雷達回波、點位在更上層的 pane。
- Leaflet zoom＝MapLibre zoom＋1（外掛內部 −1）；門牌標籤要放到 Leaflet z19 才出現。
- 中文標籤用系統字體（`localIdeographFontFamily`），Protomaps 字型只含拉丁字。
- **版權**：主地圖關掉 Leaflet 內建 attribution（會被浮動 UI 蓋住），改放在 `pages/radar.blade.php` 底帶；
  其他地圖用 Leaflet attribution（統計、透明度、後台原本關閉，因 ODbL 要求標示出處改為開啟）。
- **預先載入**：民眾地圖頁 `<head>` 預先連線 `tiles.puma.taipei`，並依 `localStorage` 的主題預載對應 style；
  容器底色設成風格的陸地色（light `#e2dfda`、dark `#1f1f1f`），避免先灰一兩秒。
- **沒有 WebGL 或 MapLibre 建構失敗**：不畫底圖；主地圖顯示一行提示，其他地圖靜默略過，點位與圖層照常。
  不退回 OSM 點陣圖磚（會被擋，退回也是空白）。
- 回報表單選點小地圖：表單關閉時 Livewire 以 `@if` 移除 DOM，Alpine `destroy()` 呼叫 `map.remove()` 釋放 WebGL context
  （不釋放的話每開一次表單多留一個 context，超過瀏覽器上限會擠掉主地圖底圖）；`webglAvailable()` 探測結果快取、探測 context 立即釋放。
- 通報列表地圖彈窗：建立當下彈窗還沒排版完成，Leaflet 量到 0×0（原本的點陣底圖也一樣全灰），
  建立後 50ms 呼叫 `invalidateSize()`（同統計頁做法）。

## 照片縮圖

- 上傳時只重新編碼成 JPEG、**不縮小**，手機原圖動輒數 MB；地圖點位彈窗只有 260×180。
- 點位 API（`MapPointsController`）讀到**已公開**照片（`Report::publicImageUrl()` 不為 null，即審核通過）時
  檢查 `-md`（長邊 1280）；缺的排入 `GenerateReportPhotoVariants`，這次先用原圖。payload 的 `img` 仍是原圖（點開看原圖），
  另加 `img_md` 給彈窗。待審照片不處理。
- **只產一種尺寸**（洋流好店、gotcha 是 thumb 256＋md 1280）：260px 寬的彈窗在高解析螢幕需要 500px 以上，
  256 會糊；hazdar 目前沒有其他需要小圖的地方。
- job：`ShouldBeUnique`（鍵＝原圖路徑，鎖 1 小時）、原圖只解碼一次、原檔已不在就直接結束、
  寫完再確認原圖還在（處理期間被刪就把剛寫的縮圖刪掉）。點位 API 快取只有數十秒，產生完不必清快取。
- `/api/v1/reports`（公開資料 API）與 CSV 匯出維持原圖網址——下游資料用途要原始解析度。
- 通報列表、後台、個人頁仍顯示原圖（審核與本人用途）。
- 刪除回報時原圖本來就不刪（既有行為），縮圖也跟著留著，公開程度與原圖相同。

## 部署注意

1. `npm ci && npm run build`（新增 maplibre-gl、@maplibre/maplibre-gl-leaflet）
2. `composer dump-autoload -o`（新 class）；有 config / route / view 快取就重建（`php artisan optimize:clear` 後照 deployment.md 重跑 cache）
3. Horizon `terminate`（新 job）
4. `.env` 刪除 `MAPTILER_API_KEY`、`HAZDAR_MAP_TILE_STYLE`（程式已不讀）

## 已知小問題

- light / dark 風格引用的 `townhall` 圖示不在 basemaps-assets 的 sprite 裡，console 有警告，少數地標缺小圖示。
