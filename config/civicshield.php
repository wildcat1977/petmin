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
];
