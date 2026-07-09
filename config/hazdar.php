<?php

return [
    // 地圖預設視野（台北市）
    'default_lat' => (float) env('RADAR_DEFAULT_LAT', 25.0330),
    'default_lng' => (float) env('RADAR_DEFAULT_LNG', 121.5654),
    'default_zoom' => (int) env('RADAR_DEFAULT_ZOOM', 13),
    'nearby_radius_km' => (float) env('RADAR_NEARBY_RADIUS_KM', 1),

    /*
     * 主題配色（spec §12 五次回饋 item 4）：底色與卡片色可由 env 微調而不必改版重編。
     * 值會以 CSS 變數（--color-radar-bg / --color-radar-card）注入 layout <head>，
     * 覆蓋 app.css @theme 的預設；任何合法 CSS 顏色皆可（#hex / rgb() / oklch() …）。
     *   bg   = 全站底色（原 #002923，PM 覺得太綠可調整）
     *   card = 資訊卡片色，比底色亮一級（原 #003a30）
     */
    // 用 ?: 而非 env 第二參數：env 值為「空字串」時（例：.env 寫 HAZDAR_COLOR_BG=#001910
    // 未加引號，# 之後被當註解 → 值變空）也要退回預設，避免注入空 CSS 變數把全站底色變透明。
    'theme' => [
        'bg' => env('HAZDAR_COLOR_BG') ?: '#002923',
        'card' => env('HAZDAR_COLOR_CARD') ?: '#003a30',
    ],

    // AI 通過後直接核准（公開照片），不需人工再審
    'auto_approve' => (bool) env('RADAR_AUTO_APPROVE', false),

    // 反濫用（spec §10-5，災時參數可由 env 調整）
    'report_cooldown_seconds' => (int) env('HAZDAR_REPORT_COOLDOWN', 60),
    'report_hourly_limit' => (int) env('HAZDAR_REPORT_HOURLY_LIMIT', 10),

    // 免責聲明（spec §11.3）：進站彈窗固定每 session 一次；
    // 回報彈窗模式 every=每次回報跳|session=每 session 一次|off=關閉
    'disclaimer' => [
        'report_mode' => env('HAZDAR_DISCLAIMER_REPORT_MODE', 'every'),
    ],

    // 佐證規則（spec §4.3）：同 geohash7 + 同分類，滑動窗內不同帳號數達標 → corroborated
    'corroboration' => [
        'window_hours' => (int) env('HAZDAR_CORROBORATION_WINDOW_HOURS', 6),
        'min_distinct_users' => (int) env('HAZDAR_CORROBORATION_MIN_USERS', 3),
    ],

    // 點位 API（spec §7.2）
    'map' => [
        'max_points' => (int) env('HAZDAR_MAP_MAX_POINTS', 2000),
        'cluster_below_zoom' => 15,          // zoom < 15 回傳 geohash 聚合
        'cache_seconds' => (int) env('HAZDAR_MAP_CACHE_SECONDS', 20),

        // 底圖 tile（測試反映 OSM 標準樣式標示過多過雜）：
        // 有設 MAPTILER_API_KEY 就預設用 MapTiler 的某個風格（HAZDAR_MAP_TILE_STYLE，預設 positron 極簡風），否則退回 OSM 標準 tile。
        // 風格名稱可在 MapTiler 後台「Standard maps」列表點進去看網址上的 slug（如 streets-v2、toner-v2、dataviz…）。
        // 要換其他供應商（如 Geoapify）直接設 HAZDAR_MAP_TILE_URL 整組覆蓋即可，不用改程式碼。
        'tile_url' => env('HAZDAR_MAP_TILE_URL', env('MAPTILER_API_KEY')
            ? 'https://api.maptiler.com/maps/'.env('HAZDAR_MAP_TILE_STYLE', 'positron').'/{z}/{x}/{y}.png?key='.env('MAPTILER_API_KEY')
            : 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'tile_attribution' => env('HAZDAR_MAP_TILE_ATTRIBUTION', env('MAPTILER_API_KEY')
            ? '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            : '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'),
        'tile_subdomains' => env('HAZDAR_MAP_TILE_SUBDOMAINS', env('MAPTILER_API_KEY') ? '' : 'abc'),
        'tile_max_zoom' => (int) env('HAZDAR_MAP_TILE_MAX_ZOOM', 19),
        'tile_size' => (int) env('HAZDAR_MAP_TILE_SIZE', env('MAPTILER_API_KEY') ? 512 : 256),
        'tile_zoom_offset' => (int) env('HAZDAR_MAP_TILE_ZOOM_OFFSET', env('MAPTILER_API_KEY') ? -1 : 0),
    ],

    // 外部資料整合層（spec §5）：scheduled command 拉 → 落地 → 前端只讀落地資料
    'sources' => [
        'cwa_observations' => [
            // ⚠ CWA 開放資料平台採授權碼制，dataset 欄位以實際回應為準（平台常改版）
            'api_key' => env('CWA_API_KEY'),
            'url' => env('CWA_OBS_URL', 'https://opendata.cwa.gov.tw/api/v1/rest/datastore/O-A0003-001'),
            'station_id' => env('CWA_STATION_ID', '466920'), // 臺北測站
            'interval_minutes' => 5,
        ],
        'ncdr_alerts' => [
            'feed_url' => env('NCDR_FEED_URL', 'https://alerts.ncdr.nat.gov.tw/RssAtomFeed.ashx'),
            'closure_feed_url' => env('NCDR_CLOSURE_FEED_URL', 'https://alerts.ncdr.nat.gov.tw/RssAtomFeed.ashx?AlertType=33'),
            'interval_minutes' => 5,
            'max_detail_fetches' => 30,      // 每輪最多抓幾筆 CAP 明細（僅新 cap_id）
        ],
        'radar_echo' => [
            // 雷達回波整合圖（免授權碼公開圖檔）；後端 proxy + cache 避免 CORS 與打爆來源
            'image_url' => env('CWA_RADAR_URL', 'https://www.cwa.gov.tw/Data/radar/CV1_3600.png'),
            // ⚠ CV1_3600 固定地理範圍（Leaflet ImageOverlay bounds），換圖源時需一併校正
            'bounds' => [[17.75, 115.0], [29.25, 126.5]],
            'interval_minutes' => 10,
        ],
        'shelters' => [
            // data.taipei「臺北市可供避難收容處所一覽表」resource id（dataset 134683）
            'resource_id' => env('TAIPEI_SHELTER_RESOURCE_ID', '4c92dbd4-d259-495a-8390-52628119a4dd'),
            'url' => env('TAIPEI_SHELTER_URL', 'https://data.taipei/api/v1/dataset/%s?scope=resourceAquire&limit=1000'),
            'interval_minutes' => 1440,
            // 此 dataset 無經緯度，需以機構名稱向 Nominatim geocode；每輪補齊上限（政策：1 req/sec）
            'geocode_per_run' => (int) env('TAIPEI_SHELTER_GEOCODE_PER_RUN', 500),
            'geocode_url' => 'https://nominatim.openstreetmap.org/search',
            'geocode_delay_us' => (int) env('TAIPEI_SHELTER_GEOCODE_DELAY_US', 1_100_000),
        ],
        'pump' => [
            // 臺北市水利處抽水站即時運轉狀態（免認證；spec §11.5 P2 實測 2026-07-08）
            'url' => env('HAZDAR_PUMP_URL', 'https://heopublic.gov.taipei/taipei-heo-api/openapi/pumb/latest'),
            'interval_minutes' => 10,
            // 抽水站靜態清冊（含 站名/行政區域），僅用於補 district，不是即時狀態源
            'inventory_resource_id' => env('TAIPEI_PUMP_INVENTORY_RESOURCE_ID', 'd1f9c6e0-5f72-4fc4-ac18-0b75ce0b002e'),
            'inventory_url' => env('TAIPEI_PUMP_INVENTORY_URL', 'https://data.taipei/api/v1/dataset/%s?scope=resourceAquire&limit=1000'),
        ],
        'floodgates' => [
            // 臺北市疏散門靜態位置（data.taipei，已 WGS84，36 座；即時狀態端點不可達故只放位置）
            'url' => env('TAIPEI_FLOODGATE_URL', 'https://data.taipei/api/v1/dataset/%s?scope=resourceAquire&limit=1000'),
            'resource_id' => env('TAIPEI_FLOODGATE_RESOURCE_ID', '47cffd30-3527-45af-b709-6f76772e3cfb'),
            'interval_minutes' => 1440,
        ],
        'transit' => [
            // TDX 運輸資料流通服務 OAuth2 client_credentials；未設定憑證時 command 直接跳過（不記為 down）
            'tdx_client_id' => env('TDX_CLIENT_ID'),
            'tdx_client_secret' => env('TDX_CLIENT_SECRET'),
            'token_url' => env('TDX_TOKEN_URL', 'https://tdx.transportdata.tw/auth/realms/TDXConnect/protocol/openid-connect/token'),
            'base_url' => env('TDX_BASE_URL', 'https://tdx.transportdata.tw/api/basic'),
            'endpoints' => [
                'metro' => '/v2/Rail/Metro/Alert/TRTC',
                'gondola' => '/v2/Rail/Metro/Alert/TRTCMG',
                'bus' => '/v2/Bus/Alert/City/Taipei',
            ],
            'interval_minutes' => 5,
        ],
    ],

    /*
     * 淹水潛勢圖層（spec §10-3，非阻塞）。
     * 此圖資無公開即插即用圖磚服務：data.gov.tw 開放資料僅 CSV/JSON 描述、
     * TGOS WMS 需申請 API key、WRA GIS 檢視器（gic.wra.gov.tw）圖磚未開放嵌入。
     * 取得來源後擇一設定即可讓前端掛上此圖層：
     *   - xyz：RESTful 圖磚模板（含 {z}/{x}/{y}），如自建切片或 NLSC WMTS RESTful
     *   - wms：標準 WMS（多數政府圖資，需 base url + layers 名稱）
     */
    'flood_potential' => [
        'xyz' => env('FLOOD_POTENTIAL_XYZ_URL'),          // e.g. https://host/tiles/{z}/{x}/{y}.png
        'wms_url' => env('FLOOD_POTENTIAL_WMS_URL'),       // e.g. https://www.tgos.tw/.../wms
        'wms_layers' => env('FLOOD_POTENTIAL_WMS_LAYERS'), // WMS layer 名稱
    ],
];
