<?php

return [
    // 地圖預設視野（台北市）
    'default_lat' => (float) env('RADAR_DEFAULT_LAT', 25.0330),
    'default_lng' => (float) env('RADAR_DEFAULT_LNG', 121.5654),
    'default_zoom' => (int) env('RADAR_DEFAULT_ZOOM', 13),
    'nearby_radius_km' => (float) env('RADAR_NEARBY_RADIUS_KM', 1),

    /*
     * 主題配色：底色與卡片色可由 env 微調而不必改版重編。
     * 值會以 CSS 變數（--color-radar-bg / --color-radar-card）注入 layout <head>。
     * 用 ?: 而非 env 第二參數：env 值為空字串時也要退回預設。
     */
    'theme' => [
        'bg' => env('PETMIN_COLOR_BG') ?: '#1c1917',
        'card' => env('PETMIN_COLOR_CARD') ?: '#292524',
    ],

    // AI 通過後直接核准（公開照片），不需人工再審
    'auto_approve' => (bool) env('RADAR_AUTO_APPROVE', false),

    // 反濫用
    'report_cooldown_seconds' => (int) env('PETMIN_REPORT_COOLDOWN', 60),
    'report_hourly_limit' => (int) env('PETMIN_REPORT_HOURLY_LIMIT', 10),

    // 回報免責聲明模式 every=每次回報跳|session=每 session 一次|off=關閉
    'disclaimer' => [
        'report_mode' => env('PETMIN_DISCLAIMER_REPORT_MODE', 'session'),
    ],

    /*
     * 佐證規則：同一個桶（trash_bin_id）或同 geohash7 + 同分類，
     * 滑動窗內不同帳號數達標 → corroborated。遛狗族密度不如災時，門檻預設 2。
     */
    'corroboration' => [
        'window_hours' => (int) env('PETMIN_CORROBORATION_WINDOW_HOURS', 24),
        'min_distinct_users' => (int) env('PETMIN_CORROBORATION_MIN_USERS', 2),
    ],

    // 回報吸附到既有桶的距離（公尺）
    'bin_snap_meters' => (int) env('PETMIN_BIN_SNAP_METERS', 30),

    // 「有袋沒桶」判定：狗便袋箱周圍此距離內無 active 清潔箱
    'bag_box_gap_meters' => (int) env('PETMIN_BAG_BOX_GAP_METERS', 100),

    // 點位 API
    'map' => [
        'max_points' => (int) env('PETMIN_MAP_MAX_POINTS', 2000),
        'cluster_below_zoom' => 15,          // zoom < 15 回傳 geohash 聚合
        'cache_seconds' => (int) env('PETMIN_MAP_CACHE_SECONDS', 20),

        // 自架 Protomaps 底圖（docs/basemap.md）。亮／暗兩種風格，主地圖切換主題時換 style
        'basemap_styles' => [
            'light' => env('PETMIN_BASEMAP_LIGHT_STYLE_URL', 'https://tiles.puma.taipei/styles/light.json'),
            'dark' => env('PETMIN_BASEMAP_DARK_STYLE_URL', 'https://tiles.puma.taipei/styles/dark.json'),
        ],
        'basemap_attribution' => '© <a href="https://protomaps.com">Protomaps</a> © <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        'max_zoom' => (int) env('PETMIN_MAP_MAX_ZOOM', 19),
    ],

    /*
     * 需求分數（DemandScoreService）。以 grid.size_m 方格為單位，每格看「格中心半徑內」的訊號（越近越重）：
     *   score = (Σ 訊號 × 距離衰減) × (1 + 缺桶加成) − Σ 半徑內現存清潔箱 × penalty × 距離衰減
     * 權重可調，demand:recompute 重算。
     */
    'demand' => [
        // 固定原點的正方格（DemandGrid）；改 size_m 或原點後要重算
        'grid' => [
            'size_m' => (int) env('PETMIN_DEMAND_GRID_M', 150),
            'origin_lat' => 25.05,
            'origin_lng' => 121.5,
        ],
        // 一般訊號（公車、袋箱、公園、回報）與現存桶扣分：格中心 radius_m 內線性衰減
        'radius_m' => (int) env('PETMIN_DEMAND_RADIUS_M', 300),
        // 捷運出口「甜甜圈」：出口旁不加權（站內垃圾桶在閘門附近，剛出站／要進站的人可用），
        // inner_m 處升到滿、outer_m 遞減到 0（出站往外走的人找不到桶也不會回站內丟）
        'mrt_exit' => [
            'inner_m' => (int) env('PETMIN_DEMAND_MRT_INNER_M', 100),
            'outer_m' => (int) env('PETMIN_DEMAND_MRT_OUTER_M', 500),
        ],
        'weights' => [
            'mrt' => (float) env('PETMIN_DEMAND_W_MRT', 8.0),          // 每站權重 √(出站量/台北車站) × 8，平分給各出口
            'bus_stop' => (float) env('PETMIN_DEMAND_W_BUS', 0.5),     // 每個站牌
            'dog_bag_box' => (float) env('PETMIN_DEMAND_W_BAG', 2.0),  // 每個狗便袋箱
            'dog_park' => (float) env('PETMIN_DEMAND_W_DOG_PARK', 3.0), // 每個狗活動區
            'park' => (float) env('PETMIN_DEMAND_W_PARK', 1.5),         // 每個一般公園／河濱入口
            'petition' => (float) env('PETMIN_DEMAND_W_PETITION', 1.0),
            'report_need_bin' => (float) env('PETMIN_DEMAND_W_NEED', 2.0),
            'report_full' => (float) env('PETMIN_DEMAND_W_FULL', 1.0),
            'corroborated_multiplier' => (float) env('PETMIN_DEMAND_CORROBORATED_X', 2.0),
            'existing_bin_penalty' => (float) env('PETMIN_DEMAND_BIN_PENALTY', 2.5),
        ],
        // 單一來源在一格的分數上限（半徑內公車站牌可能 20+ 個，不加上限會淹掉其他訊號）
        'caps' => [
            'bus_stop' => (float) env('PETMIN_DEMAND_CAP_BUS', 4.0),
            'mrt' => (float) env('PETMIN_DEMAND_CAP_MRT', 10.0),
        ],
        // 缺桶加成：格中心到最近現存清潔箱 start_m 內不加成，到 full_m 線性升到需求訊號 × max_multiplier
        // （台北各格最近清潔箱距離中位數約 700m；1000m 以上約 4 成的格子）
        'bin_gap' => [
            'start_m' => (int) env('PETMIN_DEMAND_GAP_START_M', 300),
            'full_m' => (int) env('PETMIN_DEMAND_GAP_FULL_M', 1000),
            'max_multiplier' => (float) env('PETMIN_DEMAND_GAP_MAX_X', 1.0),
        ],
        'top_n' => (int) env('PETMIN_DEMAND_TOP_N', 30),
        // 候選清單去重：格子只有 150m，同一熱點會佔好幾格；這個距離內只留分數最高的一格
        'candidate_spacing_m' => (int) env('PETMIN_DEMAND_CANDIDATE_SPACING_M', 300),
    ],

    /*
     * 官方桶位每日同步（bins:sync-open-data，排程每天 04:00）。url 為 data.taipei 來源；
     * snapshot 為 repo 內附快照（--offline 讀它，本機開發與測試用）。
     * 一次同步若有超過 removal_guard_ratio（且多於 removal_guard_min 筆）的桶從清單消失，
     * 視為 API 回傳殘缺，不套用撤除並以失敗結束。
     */
    'open_data' => [
        'http_timeout' => 20,
        'removal_guard_ratio' => 0.1,
        'removal_guard_min' => 3,
        'same_bin_meters' => 30,           // 同地址且這個距離內＝同一個桶（環保局微調座標）
        'area_duplicate_meters' => 100,    // 景點商圈清潔箱離逐點清單這個距離內＝同一批桶，跳過
        'sources' => [
            'clean_boxes' => [
                'url' => 'https://data.taipei/api/v1/dataset/267d550f-c6ec-46e0-b8af-fd5a464eb098?scope=resourceAquire&limit=1000',
                'snapshot' => database_path('data/clean_boxes.json'),
            ],
            'clean_box_areas' => [
                'url' => 'https://data.taipei/api/v1/dataset/2030d728-9597-437d-81a4-cf2a44c7f114?scope=resourceAquire&limit=1000',
                'snapshot' => database_path('data/clean_box_areas.json'),
            ],
            'dog_bag_boxes' => [
                'url' => 'https://data.taipei/api/dataset/d338aa80-2aec-4e9d-a7de-a5d980118e32/resource/2822f947-7585-4da4-a1cf-f20d1475d4f8/download',
                'snapshot' => database_path('data/dog_bag_boxes.csv'),
            ],
        ],
    ],

    // 遊戲化：核准一筆回報得幾點
    'points_per_approved_report' => (int) env('PETMIN_POINTS_PER_REPORT', 10),
];
