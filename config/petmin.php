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
     * 需求分數權重（DemandScoreService）。以 geohash6（~600m）為格：
     *   score = Σ proxy 權重 + 眾包回報（need_bin / full，已核准）× 佐證倍率 − 現存 active 清潔箱 × penalty
     * 權重可調，demand:recompute 重算。
     */
    'demand' => [
        'weights' => [
            'mrt' => (float) env('PETMIN_DEMAND_W_MRT', 3.0),          // 每站正規化後 0~1 × 3
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
        // 單一來源在一格內的分數上限（公車站牌一格可能 20+ 個，不加上限會淹掉其他訊號）
        'caps' => [
            'bus_stop' => (float) env('PETMIN_DEMAND_CAP_BUS', 4.0),
            'mrt' => (float) env('PETMIN_DEMAND_CAP_MRT', 6.0),
        ],
        'top_n' => (int) env('PETMIN_DEMAND_TOP_N', 30),
    ],

    // 遊戲化：核准一筆回報得幾點
    'points_per_approved_report' => (int) env('PETMIN_POINTS_PER_REPORT', 10),
];
