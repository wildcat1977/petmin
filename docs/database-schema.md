# 資料庫結構

沿用 ratdar 既有 `users` / `contacts` 表；`reports` 表以 delta migration 改造
（`2026_07_06_000001_adapt_reports_for_hazdar.php`），並新增四張 Hazdar 表
（`2026_07_06_000002_create_hazdar_tables.php`）。

## `users`

擴充 Laravel 預設 `users` 表，新增 Socialite 欄位（沿用 ratdar，未改動）。

| 欄位              | 型別           | 說明                        |
| ----------------- | -------------- | --------------------------- |
| id                | bigint PK      |                             |
| name              | string         |                             |
| email             | string unique  |                             |
| password          | string null    | Socialite 使用者可為 null   |
| provider          | string null    | `google` / `line`           |
| provider_id       | string null    | OAuth provider 給的唯一 id  |
| avatar            | string null    | OAuth 提供的頭像 URL        |
| is_admin          | boolean        | 後台存取                    |
| is_banned         | boolean        | 反濫用封禁                  |
| timestamps        |                |                             |

唯一索引：`(provider, provider_id)`

## `reports`

災情回報主表。`status`（內容適切性審核）與 `verification_level`（災情核實）**正交分離**：
內容審核判斷照片/內容是否可公開，災情核實判斷這筆災情是否被佐證/官方確認。

| 欄位               | 型別                                              | 說明                                                        |
| ------------------ | ------------------------------------------------- | ----------------------------------------------------------- |
| id                 | bigint PK                                         |                                                             |
| user_id            | FK → users                                        | 回報者，cascadeOnDelete                                     |
| category           | string(32)                                        | `flood` / `road_blocked` / `power_outage` / `structural_damage`，必填 |
| water_depth_cm     | smallint null                                     | 僅 `flood` 有值的選填欄位                                   |
| latitude           | decimal(10,7)                                     | 緯度（保留裸值，未被 accessor 包死）                        |
| longitude          | decimal(10,7)                                     | 經度                                                        |
| geohash7           | char(7) null, indexed                             | 寫入時由 lat/lng 推導（precision 7 ≈ 150m），佐證與聚合用   |
| address            | string null                                       | 反查地址（縣市+區），queue 後補                              |
| image_path         | string null                                       | 儲存於 `storage/app/public/reports/`；審核通過前不公開      |
| description        | text null                                         | 補充說明（選填）                                            |
| status             | enum(pending, approved, rejected)                 | 內容適切性審核，預設 `pending`                              |
| verification_level | enum(unverified, corroborated, official)          | 災情核實，預設 `unverified`                                 |
| resolved_at        | timestamp null                                    | 官方標記已排除（地圖預設隱藏）                              |
| rejection_reason   | string null                                       | 內容退件原因（key 存 DB）                                   |
| reviewed_at / first_reviewed_at | timestamp null                       | 審核時間（沿用 ratdar 慣例）                                |
| timestamps         |                                                   |                                                             |

索引：
- `geohash7`
- `(category, created_at)` — 分類統計卡片
- `(geohash7, category, created_at)` — 佐證判定滑動窗查詢

**視覺分層（spec §7.1）**：`status IN (pending, approved)` 皆上圖（災時「可能有事」的資訊價值大於等核實），
僅 `rejected` 隱藏；`unverified` 半透明 → `corroborated` 實心 → `official` 加白框；`resolved_at` 非 null 預設隱藏。

## `weather_observations`

CWA 測站觀測歷史，供 24H 雨量趨勢圖。保留 7 天，daily prune。

| 欄位          | 型別          | 說明                          |
| ------------- | ------------- | ----------------------------- |
| id            | bigint PK     |                               |
| station_id    | string(16)    | CWA 測站代號                  |
| observed_at   | timestamp     | 觀測時間                      |
| temp          | decimal(4,1) null | 溫度（-99 缺測值轉 null）  |
| rain_1h       | decimal(6,1) null | 時雨量                    |
| wind_speed    | decimal(5,1) null | 風速                      |
| visibility    | decimal(6,1) null | 能見度                    |
| raw           | jsonb null    | 原始回應                      |
| timestamps    |               |                               |

唯一索引：`(station_id, observed_at)` — 同觀測時間 upsert 不重複

即時最新值另存 Redis key `hazdar:cwa:latest`（前端不打外部 API）。

## `external_alerts`

NCDR 民生示警（CAP）快取，依 `cap_id` upsert。

| 欄位          | 型別          | 說明                                        |
| ------------- | ------------- | ------------------------------------------- |
| id            | bigint PK     |                                             |
| source        | string(32)    | 發布單位（如 農業部、台灣自來水公司）        |
| cap_id        | string unique | CAP 唯一識別                                |
| alert_type    | string(64)    | 警戒類別（如 土石流、颱風、停水）            |
| headline      | string(512)   | 摘要                                        |
| severity      | string(32) null | CAP severity（Extreme/Severe/Moderate…）  |
| areas         | jsonb null    | 影響區域 areaDesc 陣列                       |
| covers_taipei | boolean       | ingest 時判定是否涵蓋台北市（跑馬燈過濾用）  |
| effective_at / expires_at | timestamp null | 生效／失效（中文上下午時間解析）  |
| raw           | jsonb null    |                                             |
| timestamps    |               |                                             |

索引：`expires_at`、`(covers_taipei, expires_at)`
跑馬燈條件（spec §5）：未過期 + 涵蓋台北市 = `scopeActive()->coversTaipei()`。

## `shelters`

收容所（data.taipei 同步 + Filament 手動維護）。災時開設狀態 `is_open` 以後台為準，同步不覆寫。

| 欄位          | 型別              | 說明                          |
| ------------- | ----------------- | ----------------------------- |
| id            | bigint PK         |                               |
| name          | string            | 收容所名稱                    |
| latitude / longitude | decimal(10,7) | 座標                       |
| address       | string null       |                               |
| capacity      | integer null      | 容量                          |
| is_open       | boolean           | 災時開設中（後台切換，busts `map.shelters` cache） |
| source        | string(32)        | `data.taipei` / `demo`        |
| external_id   | string unique null| 同步去重                      |
| timestamps    |                   |                               |

## `dashboard_resources`

EOC 物資看板（純官方於 Filament 手動維護）。

| 欄位          | 型別          | 說明                          |
| ------------- | ------------- | ----------------------------- |
| id            | bigint PK     |                               |
| label         | string        | 物資名稱（如 沙包/防水閘板）   |
| current_qty   | integer       | 目前數量                      |
| max_qty       | integer       | 上限                          |
| unit          | string(16) null | 單位（箱/組/份…）           |
| sort_order    | integer       | 顯示排序                      |
| timestamps    |               |                               |

## `contacts`

沿用 ratdar，未改動（聯絡管理員表單）。

## ER 圖

```
users 1 ─── * reports    (user_id, geohash7 分桶佐證)
users 1 ─── * contacts   (user_id, nullable)

weather_observations · external_alerts · shelters · dashboard_resources
  └─ 獨立表，由 scheduled command 落地，不與 reports 關聯
```

## 即時性快取（不進表）

- CWA 最新觀測值 → Redis `hazdar:cwa:latest`
- 雷達回波圖檔 → `storage/app/public/radar/latest.png`（前端經 `/api/radar-echo` proxy 讀取）
- 各外部源健康狀態 → Redis `hazdar:health:{source}:*`（`DataSourceHealth`）

## Scale-up path（現在不做，只避免封死）

點位量級再上去時，geohash 聚合改 PostGIS `ST_ClusterDBSCAN` + GiST index。
`latitude`/`longitude` 保留裸值可讀，未被 accessor 包死（spec §4.4）。
