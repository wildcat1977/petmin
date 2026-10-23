# Petmin 🐾 找回消失的垃圾桶

> 遛狗順手回報：垃圾桶滿了、消失了、這裡需要一個。
> 把市民需求變成台北市垃圾桶復設的決策資料。

市政 debug 松（2026-10-23 線上提案 / 10-24 現場）議題 #2「找回消失的垃圾桶」的 MVP。
台北市行人專用清潔箱從約 2,000 組裁到 806 組，環保局的裁撤邏輯只有供給端訊號
（被亂丟 → 撤、里長要求 → 撤），市民端「哪裡需要」完全沒有資料。Petmin 補上這一半。

完整提案見 [docs/proposal.md](docs/proposal.md)；五分鐘 demo 腳本見 [docs/demo-script.md](docs/demo-script.md)。

本專案 fork 自 [hazdar（災害回報）](https://github.com/wildcat1977/hazdar)，再往上是
[ratdar（見鼠地圖）](https://github.com/wildcat1977/ratdar)；沿用回報 pipeline、Socialite 登入、
Leaflet + 自架 Protomaps 底圖、Claude Vision 審核、Filament 後台。

## 三層設計

1. **找回**：環保局 open data 的清潔箱（2026-10-23：733 組＋景點商圈 95 處）與狗便清潔袋箱（468 個）上圖（每天 04:00 自動同步）；市民回報「桶還在 / 桶消失了」，
   核准後直接改桶的狀態（`TrashBin.status`）。
2. **回報**：遛狗族 1 分鐘內完成——拍照 → EXIF 自動定位 → 吸附 30m 內最近的桶 → 選狀況
   （需要桶 / 滿了 / 消失了 / 還在 / 袋箱沒袋 / 家戶垃圾 / 毒餌）。同桶 24h 內 2 個帳號 → 自動「多方佐證」。
3. **決策**：`demand:recompute` 以 150m 方格算需求分數，每格看格中心附近的訊號（越近越重，不看落在哪一格）=
   （人流 proxy（捷運出入口、公車站牌）+ 遛狗 proxy（遛狗地點＝150m 內的狗便袋箱算一處、狗活動區）+ 市民回報（佐證加倍））× 缺桶加成 − 附近現存清潔箱；候選排行只排臺北市（新北只有捷運資料，地圖照畫）。
   捷運從出口往外算（站內桶在閘門旁，出口旁不加權、100m 最高、500m 淡掉）；缺桶加成看格中心到最近清潔箱的距離：
   300m 內不加成、1000m 以上需求加倍。參數都在 `config/petmin.php` 的 `demand`。
   `/candidates` 列候選復設點 Top N（附證據明細、CSV）、撤除風險（家戶垃圾集中）、
   **有袋沒桶**（狗便袋箱 100m 內無清潔箱：2026-10-23：267 / 468）。

遊戲化：核准回報 +10 點、連續天數 streak、`/leaderboard` 巡桶英雄榜；獎勵提案為「點數換專用垃圾袋」。

## 技術堆疊

| 領域       | 套件 / 版本                                              |
| ---------- | -------------------------------------------------------- |
| 後端框架   | Laravel 13 / PHP 8.3+（composer.json 寫 ^8.4，8.3 可 `--ignore-platform-req=php`） |
| 前端互動   | Livewire 4（SFC）+ Alpine                                 |
| CSS        | Tailwind CSS 4 + Vite 8                                   |
| 地圖       | Leaflet 1.9 + MapLibre GL（自架 Protomaps 向量圖磚）+ leaflet.heat |
| 認證       | Laravel Socialite 5（Google + LINE + LINE LIFF）          |
| 後台       | Filament 5                                                |
| 資料庫     | PostgreSQL（正式）/ SQLite（本機、測試）；無 PostGIS，geohash + Haversine |
| AI 審核    | Claude Vision（照片適切性 + 分類吻合；fail-open 留人工）   |

## 快速啟動

```bash
cp .env.example .env            # 本機可改 DB_CONNECTION=sqlite
php artisan key:generate
composer install                # PHP 8.3 加 --ignore-platform-req=php
npm install && npm run build
php artisan migrate --seed      # local 環境會跑 DemoSeeder：匯入 open data + 假回報 + 需求分數
php artisan make:filament-user
php artisan serve
```

- 地圖首頁      <http://localhost:8000>
- 候選復設點    <http://localhost:8000/candidates>
- 巡桶英雄榜      <http://localhost:8000/leaderboard>
- 回報清單      <http://localhost:8000/reports>
- 後台          <http://localhost:8000/admin>

## Open data 與匯入

資料快照放 `database/data/`（Big5 已轉 UTF-8），匯入指令讀本機檔、不依賴現場網路，重跑冪等：

```bash
php artisan bins:sync-open-data         # 官方桶位：清潔箱 681、景點商圈清潔箱 98 處、狗便清潔袋箱 468（排程每天 04:00；--offline 讀快照）
php artisan proxies:import-bus-stops     # 公車站牌 3,407
php artisan proxies:import-dog-parks     # 狗活動區／公園（手刻 JSON，座標待勘誤）
php artisan proxies:import-mrt                # 捷運站點 119 站（weight 已由 OD 出站量換算，見 database/data/README.md）
php artisan proxies:import-mrt-exits          # 捷運出入口 390 個（站權重平分給各出口；需求分數用這個）
php artisan demand:recompute             # 需求分數（排程每小時）
php artisan bins:gap-report [--csv=path] # 有袋沒桶清單
```

已知限制：清潔箱 open data 是「現存」清單，不是裁撤前的 ~1,900 組，「消失的桶」只能靠回報累積；
寵物登記沒有行政區層級 open data，遛狗密度靠 proxy。

## 目錄總覽

```
app/
├── Console/Commands/
│   ├── ImportTrashBins.php (abstract) · ImportCleanBoxes · ImportDogBagBoxes
│   ├── ImportBusStops · ImportMrtStations · ImportDogParks
│   ├── BagBoxGapReport.php            # 有袋沒桶
│   └── RecomputeDemand.php            # demand_cells 重算
├── Http/Controllers/Api/
│   ├── MapPointsController.php        # 回報點位（bbox + geohash 聚合）
│   └── BinsController.php             # 桶位（bbox + 聚合）+ nearby 吸附
├── Jobs/EvaluateCorroboration.php     # 同桶／同格 2 帳號 → corroborated
├── Observers/ReportObserver.php       # 核准 → 桶狀態同步 + 加點 + streak
├── Models/ TrashBin · DemandProxy · DemandCell · Report · ReportCategory · User
├── Services/
│   ├── DemandScoreService.php         # 需求分數（純程式，權重 config/petmin.php）
│   ├── BagBoxGapService.php           # 有袋沒桶
│   └── ImageModerationService.php     # Claude Vision
└── Filament/Resources/ Reports · TrashBins · ReportCategories · Users · Announcements · Contacts

resources/
├── js/app.js                          # 地圖：回報層 / 桶位層 / 有袋沒桶 / 需求熱力 / 表單吸附
└── views/
    ├── pages/radar.blade.php          # 地圖首頁（圖層 chips）
    ├── pages/candidates.blade.php     # 候選復設點 / 撤除風險 / 有袋沒桶
    ├── livewire/report-form.blade.php # 回報表單（吸附桶、從桶 popup 預帶）
    └── livewire/leaderboard.blade.php

database/data/                         # open data 快照（見內附 README）
config/petmin.php                      # 地圖、反濫用、佐證、吸附距離、需求權重、點數
```

## 測試

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

## 文件

- [docs/proposal.md](docs/proposal.md) — 提案計畫書
- [docs/demo-script.md](docs/demo-script.md) — demo 腳本
- [docs/deployment.md](docs/deployment.md) — 部署
- [docs/development.md](docs/development.md) — 本地開發
- [docs/basemap.md](docs/basemap.md) — 自架底圖
