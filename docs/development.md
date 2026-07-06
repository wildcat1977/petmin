# 本地開發

## 一鍵啟動

```bash
composer dev
```

`composer.json` 內建腳本會用 [concurrently](https://www.npmjs.com/package/concurrently) 同時跑：

- `php artisan serve` — Laravel server
- `php artisan queue:listen` — Queue worker（AI 審核 / 佐證判定）
- `php artisan pail` — 即時 log 監看
- `npm run dev` — Vite HMR

## 個別命令

```bash
php artisan serve              # 8000 port
npm run dev                    # Vite
php artisan horizon            # Production-like queue dashboard
php artisan migrate:fresh --seed  # 重建 + 示範災情資料（僅 local 產生）
```

`DemoDisasterSeeder` 會在台北市 12 行政區灑約 190 筆各類回報，並刻意製造兩個佐證群
（同網格 3 個不同帳號 → 自動升級 corroborated）與一筆官方核實，供驗證地圖視覺分層。
> 注意：`DatabaseSeeder` **不可**用 `WithoutModelEvents`——`geohash7` 推導與佐證判定都掛在
> Report model events 上，關掉就不會觸發。跑 seed 後記得讓 queue worker 消化佐證 job。

## 撰寫 Livewire 4 組件（SFC）

前台頁面使用 single-file component 寫法，PHP class 與 Blade 同檔：

```
resources/views/livewire/{name}.blade.php
```

```php
<?php
use Livewire\Component;

new class extends Component
{
    public string $msg = 'Hi';
}; ?>

<div>{{ $msg }}</div>
```

引用：`<livewire:name />`

## 測試

```bash
php artisan test --compact
# 針對單一檔案
php artisan test --compact tests/Feature/CorroborationTest.php
```

測試 DB 為 sqlite `:memory:`（`phpunit.xml`）。重點測試：

| 檔案 | 涵蓋 |
| ---- | ---- |
| `tests/Unit/GeohashTest.php` | geohash 編碼參考向量、網格覆蓋 |
| `tests/Feature/CorroborationTest.php` | 佐證升級（3 帳號/6h）、同帳號洗版不算、窗外不算、official 不被觸碰 |
| `tests/Feature/Api/MapPointsApiTest.php` | bbox 過濾、高低 zoom 模式、照片僅審核後公開、筆數上限 |
| `tests/Feature/ReportSubmissionTest.php` | 寫入即回應、cooldown/時限、淹水深度僅 flood |
| `tests/Feature/ModerateReportImageJobTest.php` | 明確違規退件、不吻合分類留人工、API fail-open |
| `tests/Feature/Commands/*` | CWA/NCDR 拉取、健康狀態燈、失敗不默默跳過 |
| `tests/Feature/EocDashboardTest.php` | 統計定義、外部中斷降級面板、footer 真實健康 |

## 程式碼風格

```bash
php vendor/bin/pint --dirty --format agent
```

> 本環境 `vendor/bin/pint` 可能無執行權限，請以 `php vendor/bin/pint` 呼叫。

## Tip：手機實機測試

```bash
php artisan serve --host=0.0.0.0
npm run dev -- --host
```

`.env` 設 `APP_URL=http://192.168.x.x:8000`。地理位置 API 需要 HTTPS 或 `localhost`；
實機測試可用 `mkcert` 做本地憑證，或 `ngrok http 8000` 取得 https URL。

## 除錯：外部資料源

各 fetch command 可獨立執行觀察落地結果與健康燈：

```bash
php artisan hazdar:fetch-ncdr-alerts   # 落地 external_alerts，印出 new/total
php artisan tinker --execute 'print_r(app(App\Services\DataSourceHealth::class)->all());'
```
