<?php

return [
    // 地圖預設視野（台北市）
    'default_lat' => (float) env('RADAR_DEFAULT_LAT', 25.0330),
    'default_lng' => (float) env('RADAR_DEFAULT_LNG', 121.5654),
    'default_zoom' => (int) env('RADAR_DEFAULT_ZOOM', 13),
    'nearby_radius_km' => (float) env('RADAR_NEARBY_RADIUS_KM', 1),

    // AI 通過後直接核准（公開照片），不需人工再審
    'auto_approve' => (bool) env('RADAR_AUTO_APPROVE', false),

    // 反濫用（spec §10-5，災時參數可由 env 調整）
    'report_cooldown_seconds' => (int) env('CIVICSHIELD_REPORT_COOLDOWN', 60),
    'report_hourly_limit' => (int) env('CIVICSHIELD_REPORT_HOURLY_LIMIT', 10),

    // 佐證規則（spec §4.3）：同 geohash7 + 同分類，滑動窗內不同帳號數達標 → corroborated
    'corroboration' => [
        'window_hours' => (int) env('CIVICSHIELD_CORROBORATION_WINDOW_HOURS', 6),
        'min_distinct_users' => (int) env('CIVICSHIELD_CORROBORATION_MIN_USERS', 3),
    ],

    // 點位 API（spec §7.2）
    'map' => [
        'max_points' => (int) env('CIVICSHIELD_MAP_MAX_POINTS', 2000),
        'cluster_below_zoom' => 15,          // zoom < 15 回傳 geohash 聚合
        'cache_seconds' => (int) env('CIVICSHIELD_MAP_CACHE_SECONDS', 20),
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
