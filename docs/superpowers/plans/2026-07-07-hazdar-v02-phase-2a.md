# Hazdar v0.2 Phase 2a — 雷達回波對位校正 + 免責聲明彈窗 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 修正雷達回波圖層北偏 ~20km 的投影 bug（spec §11.2），並加入進站/回報兩段式免責聲明彈窗（spec §11.3）。

**Architecture:** 雷達校正在後端落地時做（`FetchRadarEcho` 寫檔前以 GD 將等經緯 PNG 逐列重採樣為 Web Mercator 對齊），bounds 與前端 JS 完全不動。免責聲明為純前端 Alpine 彈窗：進站彈窗掛全站 layout（sessionStorage 每 session 一次）；回報彈窗掛 report-form 開啟區塊，模式由 `HAZDAR_DISCLAIMER_REPORT_MODE` env 控制（`every|session|off`，預設 `every`）。

**Tech Stack:** Laravel 13 / PHP 8.4（GD 擴充已安裝）/ Livewire 4 SFC（匿名 class blade）/ Alpine.js（Livewire 內建）/ Tailwind 4 / PHPUnit 12。

## Global Constraints

- 測試一律 PHPUnit class（不用 Pest），Feature 測試優先，用 `RefreshDatabase`。
- 跑單一測試：`php artisan test --compact tests/路徑/檔名.php`。
- 改過 PHP 後、commit 前必跑：`vendor/bin/pint --dirty --format agent`。
- UI 文字用 `__('中文字串')` 當 key，並在 `lang/en.json` 補英文對照（`lang/zh_TW.json` 不用加——key 即中文）。
- 免責文案一字不改：「使用前請注意：本地圖彙整網友回報與氣象局雨量回報所得之災情資訊，僅供大眾參考，不代表市府公告，緊急事故請直接撥打119/1999，切勿僅依賴本平台等待救援。希望市民朋友保護自身安全，如非必要請勿出門。」
- `config('hazdar.sources.radar_echo.bounds')` 維持 `[[17.75, 115.0], [29.25, 126.5]]`，前端 `resources/js/app.js` 的雷達 overlay 程式碼不得修改。
- Commit message 用既有慣例（`feat:`/`fix:` 前綴），結尾加 `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`。
- `[x-cloak] { display: none !important; }` 已存在於 `resources/css/app.css:4`，不用重複加。

---

### Task 1: MercatorReprojector（等經緯 → Mercator 逐列重採樣）

**Files:**
- Create: `app/Support/MercatorReprojector.php`
- Test: `tests/Unit/MercatorReprojectorTest.php`

**Interfaces:**
- Consumes: 無（純 GD，無外部依賴，比照 `app/Support/Geohash.php` 風格）
- Produces: `MercatorReprojector::reproject(string $pngBytes, float $latMin, float $latMax): string`（回傳重採樣後 PNG bytes；解析失敗丟 `RuntimeException`）。Task 2 依賴此簽章。

**背景（寫給不熟投影的人）：** Leaflet `ImageOverlay` 把圖檔在「投影後（Web Mercator）」的座標之間線性拉伸；CWA 雷達回波原圖卻是等經緯（每像素列 = 等緯度差）。兩者在圖檔上下緣重合、中間錯開——在台灣緯度約北偏 20km（100 列高的圖約錯 2 列）。修法：輸出圖每一列先算出它在 Mercator 等分下代表的緯度，再回頭取等經緯原圖中該緯度的那一列。上下緣不動、透明像素保留。

- [ ] **Step 1: 寫失敗的 unit test**

```php
<?php

namespace Tests\Unit;

use App\Support\MercatorReprojector;
use PHPUnit\Framework\TestCase;

/**
 * 等經緯 → Web Mercator 逐列重採樣（spec §11.2）。
 * CV1 bounds（17.75~29.25N）下，台灣緯度像素應向南（圖面下方）修約 1~3 列/百列，
 * 圖檔上下緣錨定不動、透明像素保持透明。
 */
class MercatorReprojectorTest extends TestCase
{
    private const LAT_MIN = 17.75;

    private const LAT_MAX = 29.25;

    /** 建 100×100 透明底圖，於指定列畫一條不透明紅線，回傳 PNG bytes */
    private function pngWithStripeAtRow(int $row): string
    {
        $img = imagecreatetruecolor(100, 100);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imageline($img, 0, $row, 99, $row, imagecolorallocatealpha($img, 255, 0, 0, 0));

        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    /** 找出第一條不透明紅線所在列，找不到回 -1 */
    private function findStripeRow(string $pngBytes): int
    {
        $img = imagecreatefromstring($pngBytes);
        for ($y = 0; $y < imagesy($img); $y++) {
            $rgba = imagecolorsforindex($img, imagecolorat($img, 50, $y));
            if ($rgba['alpha'] < 64 && $rgba['red'] > 128) {
                return $y;
            }
        }

        return -1;
    }

    public function test_mid_latitude_stripe_shifts_south(): void
    {
        // 列 37 ≈ 24.95°N（台北附近）：等經緯直接疊 Mercator 會北偏，重採樣後應向南修 1~3 列
        $out = (new MercatorReprojector)->reproject($this->pngWithStripeAtRow(37), self::LAT_MIN, self::LAT_MAX);

        $found = $this->findStripeRow($out);
        $this->assertGreaterThan(37, $found, '重採樣後紅線應向南（圖面下方）移動');
        $this->assertLessThanOrEqual(40, $found, '位移量級應為 1~3 列（≈20km／百列圖）');
    }

    public function test_edges_are_anchored(): void
    {
        $reprojector = new MercatorReprojector;

        $top = $this->findStripeRow($reprojector->reproject($this->pngWithStripeAtRow(0), self::LAT_MIN, self::LAT_MAX));
        $bottom = $this->findStripeRow($reprojector->reproject($this->pngWithStripeAtRow(99), self::LAT_MIN, self::LAT_MAX));

        $this->assertSame(0, $top);
        $this->assertSame(99, $bottom);
    }

    public function test_dimensions_and_transparency_preserved(): void
    {
        $out = imagecreatefromstring(
            (new MercatorReprojector)->reproject($this->pngWithStripeAtRow(37), self::LAT_MIN, self::LAT_MAX)
        );

        $this->assertSame(100, imagesx($out));
        $this->assertSame(100, imagesy($out));

        $rgba = imagecolorsforindex($out, imagecolorat($out, 50, 90));
        $this->assertSame(127, $rgba['alpha'], '未著色區域應保持全透明');
    }

    public function test_invalid_bytes_throw(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MercatorReprojector)->reproject('not a png', self::LAT_MIN, self::LAT_MAX);
    }
}
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact tests/Unit/MercatorReprojectorTest.php`
Expected: FAIL — `Class "App\Support\MercatorReprojector" not found`

- [ ] **Step 3: 實作**

```php
<?php

namespace App\Support;

use RuntimeException;

/**
 * 等經緯（equirectangular）PNG 逐列重採樣為 Web Mercator 對齊（spec §11.2）。
 *
 * Leaflet ImageOverlay 把圖檔在投影後座標間線性拉伸；CWA 雷達回波原圖是
 * 等經緯投影，直接疊圖會讓台灣緯度像素北偏約 20km。落地前先重採樣校正，
 * bounds 與前端完全不動。
 */
class MercatorReprojector
{
    public function reproject(string $pngBytes, float $latMin, float $latMax): string
    {
        $src = @imagecreatefromstring($pngBytes);
        if ($src === false) {
            throw new RuntimeException('無法解析雷達回波圖檔');
        }

        imagepalettetotruecolor($src);

        $width = imagesx($src);
        $height = imagesy($src);

        $dst = imagecreatetruecolor($width, $height);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));

        $yTop = self::latToMercatorY($latMax);
        $yBottom = self::latToMercatorY($latMin);

        for ($row = 0; $row < $height; $row++) {
            // 輸出列於 Mercator 空間等分 → 反推緯度 → 取等經緯原圖對應列
            $mercY = $yTop - ($row / max($height - 1, 1)) * ($yTop - $yBottom);
            $lat = self::mercatorYToLat($mercY);
            $srcRow = (int) round(($latMax - $lat) / ($latMax - $latMin) * ($height - 1));

            imagecopy($dst, $src, 0, $row, 0, max(0, min($height - 1, $srcRow)), $width, 1);
        }

        ob_start();
        imagepng($dst);
        $out = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $out;
    }

    private static function latToMercatorY(float $lat): float
    {
        return log(tan(M_PI / 4 + deg2rad($lat) / 2));
    }

    private static function mercatorYToLat(float $y): float
    {
        return rad2deg(2 * atan(exp($y)) - M_PI / 2);
    }
}
```

- [ ] **Step 4: 跑測試確認通過**

Run: `php artisan test --compact tests/Unit/MercatorReprojectorTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/MercatorReprojector.php tests/Unit/MercatorReprojectorTest.php
git commit -m "feat: MercatorReprojector 等經緯轉 Mercator 逐列重採樣（spec §11.2）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: FetchRadarEcho 落地前重採樣

**Files:**
- Modify: `app/Console/Commands/FetchRadarEcho.php`（`handle()`，約 26–58 行）
- Test: `tests/Feature/Commands/FetchRadarEchoTest.php`（新檔）

**Interfaces:**
- Consumes: `App\Support\MercatorReprojector::reproject(string, float, float): string`（Task 1）；`App\Services\DataSourceHealth`（既有：`recordSuccess`/`recordFailure`/`status()['status']`，常數 `STATUS_OK`/`STATUS_DOWN`）
- Produces: 無新介面。落地檔 `storage/app/public/radar/latest.png` 內容改為重採樣後 PNG（尺寸不變），前端不需改。

- [ ] **Step 1: 寫失敗的 feature test**

```php
<?php

namespace Tests\Feature\Commands;

use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 雷達回波拉取（spec §5、§11.2）：落地前重採樣為 Mercator 對齊、尺寸不變；
 * 非圖片回應必須亮紅燈且不落地（驗收 §1-5）。
 */
class FetchRadarEchoTest extends TestCase
{
    use RefreshDatabase;

    /** 60×60 透明底 + 20 列紅線的合成雷達圖 */
    private function fakeRadarPng(): string
    {
        $img = imagecreatetruecolor(60, 60);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imageline($img, 0, 20, 59, 20, imagecolorallocatealpha($img, 255, 0, 0, 0));

        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_image_is_reprojected_and_cached(): void
    {
        Storage::fake('public');
        Http::fake(['www.cwa.gov.tw/*' => Http::response($this->fakeRadarPng(), 200, ['Content-Type' => 'image/png'])]);

        $this->artisan('hazdar:fetch-radar-echo')->assertSuccessful();

        Storage::disk('public')->assertExists('radar/latest.png');

        // 重採樣後仍為同尺寸有效 PNG（bounds 不變、前端不用改）
        $stored = imagecreatefromstring(Storage::disk('public')->get('radar/latest.png'));
        $this->assertSame(60, imagesx($stored));
        $this->assertSame(60, imagesy($stored));

        $this->assertNotNull(Cache::get('hazdar:radar:meta'));
        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('radar_echo')['status']);
    }

    public function test_non_image_response_degrades_health(): void
    {
        Storage::fake('public');
        Http::fake(['www.cwa.gov.tw/*' => Http::response('<html>error</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->artisan('hazdar:fetch-radar-echo')->assertFailed();

        Storage::disk('public')->assertMissing('radar/latest.png');
        $this->assertSame(DataSourceHealth::STATUS_DOWN, app(DataSourceHealth::class)->status('radar_echo')['status']);
    }
}
```

- [ ] **Step 2: 跑測試確認現況**

Run: `php artisan test --compact tests/Feature/Commands/FetchRadarEchoTest.php`
Expected: `test_non_image_response_degrades_health` PASS（既有行為）；`test_image_is_reprojected_and_cached` 目前也會 PASS——因為它只驗證尺寸不變。**因此本 task 由下一步的實作差異保證**：完成 Step 3 後，額外以 Task 1 的 unit test 涵蓋重採樣正確性；此 feature test 的角色是驗證 wiring（command 有呼叫 reprojector 而不會把管線弄壞）。照常繼續。

- [ ] **Step 3: 修改 `FetchRadarEcho::handle()`**

`use` 區塊加：

```php
use App\Support\MercatorReprojector;
```

`handle()` 簽章與落地段改為（method injection，Laravel command 支援）：

```php
    public function handle(DataSourceHealth $health, MercatorReprojector $reprojector): int
    {
        $config = config('hazdar.sources.radar_echo');

        try {
            $response = Http::timeout(10)->get($config['image_url']);
            if (! $response->successful()) {
                throw new RuntimeException("radar HTTP {$response->status()}");
            }
            if (! str_starts_with($response->header('Content-Type'), 'image/')) {
                throw new RuntimeException('radar 回應非圖片（'.$response->header('Content-Type').'）');
            }

            // 等經緯 → Mercator 逐列重採樣（spec §11.2），bounds 與前端不動
            [[$latMin], [$latMax]] = $config['bounds'];
            $png = $reprojector->reproject($response->body(), $latMin, $latMax);

            Storage::disk('public')->put('radar/latest.png', $png);
            Cache::forever('hazdar:radar:meta', [
                'fetched_at' => now()->toIso8601String(),
                'bytes' => strlen($png),
            ]);

            $health->recordSuccess('radar_echo');
            $this->info('Radar echo image cached ('.strlen($png).' bytes)');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('hazdar: radar echo fetch failed', ['error' => $e->getMessage()]);
            $health->recordFailure('radar_echo', $e->getMessage());
            $this->error("Radar echo fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
```

（catch 段不變，僅示意完整方法。）

- [ ] **Step 4: 跑測試確認通過**

Run: `php artisan test --compact tests/Feature/Commands/FetchRadarEchoTest.php tests/Unit/MercatorReprojectorTest.php`
Expected: PASS（6 tests）

- [ ] **Step 5: 手動端對端驗證（驗收 §11.9-2）**

```bash
php artisan hazdar:fetch-radar-echo
```

Expected: `Radar echo image cached (N bytes)`。然後開首頁打開雷達回波圖層，以台北市行政區界對照回波位置——會議截圖中桃園上空的回波團應落回正確位置（目測 < 2km）。若本機 CWA 連不上，此步改在部署後驗證，並在 checkpoint 記錄「未驗證項」。

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/FetchRadarEcho.php tests/Feature/Commands/FetchRadarEchoTest.php
git commit -m "fix: 雷達回波落地前重採樣為 Mercator 對齊，修正北偏 ~20km（spec §11.2）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: 免責聲明 — config + 進站彈窗

**Files:**
- Modify: `config/hazdar.php`（`report_hourly_limit` 之後插入 disclaimer 區塊）
- Create: `resources/views/partials/disclaimer-modal.blade.php`
- Modify: `resources/views/layouts/app.blade.php:60-65`（body 內、`@livewireScripts` 前加 include）
- Modify: `lang/en.json`（補英文對照）
- Test: `tests/Feature/DisclaimerTest.php`（新檔）

**Interfaces:**
- Consumes: 無
- Produces: `config('hazdar.disclaimer.report_mode')`（string：`every|session|off`，Task 4 依賴）；sessionStorage key `hazdar:disclaimer:entry`（進站彈窗已讀標記）

- [ ] **Step 1: 寫失敗的 feature test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 免責聲明（spec §11.3）：進站彈窗掛全站 layout（sessionStorage 每 session 一次，
 * 顯示邏輯在前端）；回報彈窗模式由 config 控制（every|session|off）。
 */
class DisclaimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_disclaimer_renders_on_home(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('使用前請注意')
            ->assertSee('僅供大眾參考')
            ->assertSee('hazdar:disclaimer:entry', false);
    }
}
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact tests/Feature/DisclaimerTest.php`
Expected: FAIL — 回應不含「使用前請注意」

- [ ] **Step 3: 實作**

`config/hazdar.php` 在 `'report_hourly_limit' => ...` 行之後插入：

```php
    // 免責聲明（spec §11.3）：進站彈窗固定每 session 一次；
    // 回報彈窗模式 every=每次回報跳|session=每 session 一次|off=關閉
    'disclaimer' => [
        'report_mode' => env('HAZDAR_DISCLAIMER_REPORT_MODE', 'every'),
    ],
```

新檔 `resources/views/partials/disclaimer-modal.blade.php`：

```blade
{{-- 免責聲明（spec §11.3）：進站每 browser session 跳一次，已讀記 sessionStorage --}}
<div x-data="{ show: ! sessionStorage.getItem('hazdar:disclaimer:entry') }"
     x-show="show"
     x-cloak
     class="fixed inset-0 z-[3000] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
    <div class="w-full max-w-md rounded-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10">
        <h2 class="text-lg font-bold">⚠️ {{ __('使用前請注意') }}</h2>
        <p class="mt-3 text-sm leading-relaxed text-slate-300">
            {{ __('本地圖彙整網友回報與氣象局雨量回報所得之災情資訊，僅供大眾參考，不代表市府公告，緊急事故請直接撥打119/1999，切勿僅依賴本平台等待救援。希望市民朋友保護自身安全，如非必要請勿出門。') }}
        </p>
        <button type="button"
                @click="sessionStorage.setItem('hazdar:disclaimer:entry', '1'); show = false"
                class="mt-5 w-full rounded-xl bg-cyan-600 px-4 py-3 font-semibold hover:bg-cyan-500">
            {{ __('我知道了') }}
        </button>
    </div>
</div>
```

`resources/views/layouts/app.blade.php` 的 body 改為：

```blade
<body class="bg-[#0d1117] text-slate-100 antialiased min-h-dvh">
    {{ $slot ?? '' }}
    @yield('content')

    @include('partials.disclaimer-modal')

    @livewireScripts
</body>
```

`lang/en.json` 補（維持 JSON 逗號正確）：

```json
    "使用前請注意": "Before You Use This Map",
    "本地圖彙整網友回報與氣象局雨量回報所得之災情資訊，僅供大眾參考，不代表市府公告，緊急事故請直接撥打119/1999，切勿僅依賴本平台等待救援。希望市民朋友保護自身安全，如非必要請勿出門。": "This map aggregates crowd-sourced reports and CWA rainfall data for public reference only and does not represent official city announcements. In an emergency, call 119/1999 immediately — do not rely on this platform to wait for rescue. Please stay safe and avoid going out unless necessary.",
    "我知道了": "Got It",
```

- [ ] **Step 4: 跑測試確認通過**

Run: `php artisan test --compact tests/Feature/DisclaimerTest.php`
Expected: PASS

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/hazdar.php resources/views/partials/disclaimer-modal.blade.php resources/views/layouts/app.blade.php lang/en.json tests/Feature/DisclaimerTest.php
git commit -m "feat: 進站免責聲明彈窗（每 session 一次，spec §11.3）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: 免責聲明 — 回報彈窗（含 env 開關）

**Files:**
- Modify: `resources/views/livewire/report-form.blade.php`（template 區 `@if ($open)` 內、既有表單 overlay 之前）
- Modify: `lang/en.json`（補一個按鈕文案）
- Test: `tests/Feature/DisclaimerTest.php`（追加 3 個測試）

**Interfaces:**
- Consumes: `config('hazdar.disclaimer.report_mode')`（Task 3）；report-form 既有 `#[On('open-report-form')] show()`（無參數、需登入）與 `public bool $open` / `public bool $submitted`
- Produces: sessionStorage key `hazdar:disclaimer:report`（session 模式的已讀標記）

**行為規格：** `every`（預設）＝每次開啟回報表單都跳（`@if ($open)` 區塊每次重新 mount，Alpine `ack` 重設）；`session`＝每 browser session 第一次開表單跳；`off`＝完全不渲染。已提交成功畫面（`$submitted`）不再蓋彈窗。按「我知道了，開始回報」才看得到表單。

- [ ] **Step 1: 追加失敗的測試（DisclaimerTest 內）**

```php
    public function test_report_form_disclaimer_shows_by_default(): void
    {
        $user = \App\Models\User::factory()->create();

        \Livewire\Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form')
            ->assertSee('僅供大眾參考')
            ->assertSee('我知道了，開始回報');
    }

    public function test_report_form_disclaimer_off_mode_renders_nothing(): void
    {
        config(['hazdar.disclaimer.report_mode' => 'off']);
        $user = \App\Models\User::factory()->create();

        \Livewire\Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form')
            ->assertDontSee('僅供大眾參考');
    }

    public function test_report_form_disclaimer_session_mode_renders_with_session_flag(): void
    {
        config(['hazdar.disclaimer.report_mode' => 'session']);
        $user = \App\Models\User::factory()->create();

        // session 模式仍需渲染 markup（已讀與否由前端 sessionStorage 決定）
        \Livewire\Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form')
            ->assertSee('僅供大眾參考')
            ->assertSee('hazdar:disclaimer:report', false);
    }
```

（頂部 `use` 已有的話改用 import 風格，跟檔案現況一致即可。）

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact tests/Feature/DisclaimerTest.php`
Expected: 新增 3 個測試 FAIL（找不到「僅供大眾參考」／「我知道了，開始回報」）；`off` 模式那支此時會 PASS（本來就沒有）——屬預期。

- [ ] **Step 3: 實作**

`resources/views/livewire/report-form.blade.php` template 區，`@if ($open)` 之後、既有 `<div class="fixed inset-0 z-[1000] ...">` 表單 overlay **之前**插入：

```blade
        @php $disclaimerMode = config('hazdar.disclaimer.report_mode', 'every'); @endphp
        @if ($disclaimerMode !== 'off' && ! $submitted)
            {{-- 回報免責聲明（spec §11.3）：every=每次開表單重跳；session=每 session 一次 --}}
            <div x-data="{ ack: @js($disclaimerMode) === 'session' && !! sessionStorage.getItem('hazdar:disclaimer:report') }"
                 x-show="! ack"
                 x-cloak
                 class="fixed inset-0 z-[1100] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm">
                <div class="w-full max-w-md rounded-3xl bg-[#161b22] p-6 text-slate-100 shadow-2xl ring-1 ring-white/10">
                    <h2 class="text-lg font-bold">⚠️ {{ __('使用前請注意') }}</h2>
                    <p class="mt-3 text-sm leading-relaxed text-slate-300">
                        {{ __('本地圖彙整網友回報與氣象局雨量回報所得之災情資訊，僅供大眾參考，不代表市府公告，緊急事故請直接撥打119/1999，切勿僅依賴本平台等待救援。希望市民朋友保護自身安全，如非必要請勿出門。') }}
                    </p>
                    <button type="button"
                            @click="sessionStorage.setItem('hazdar:disclaimer:report', '1'); ack = true"
                            class="mt-5 w-full rounded-xl bg-cyan-600 px-4 py-3 font-semibold hover:bg-cyan-500">
                        {{ __('我知道了，開始回報') }}
                    </button>
                </div>
            </div>
        @endif
```

`lang/en.json` 補：

```json
    "我知道了，開始回報": "Got It — Start Reporting",
```

- [ ] **Step 4: 跑測試確認通過**

Run: `php artisan test --compact tests/Feature/DisclaimerTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: 回歸測試（回報 pipeline 沒被弄壞）**

Run: `php artisan test --compact tests/Feature/ReportSubmissionTest.php`
Expected: PASS

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/report-form.blade.php lang/en.json tests/Feature/DisclaimerTest.php
git commit -m "feat: 回報免責聲明彈窗，HAZDAR_DISCLAIMER_REPORT_MODE 可切 every|session|off（spec §11.3）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Phase 2a 收尾驗證

- [ ] **Step 1: 全套測試**

Run: `php artisan test --compact`
Expected: 全綠（0 failures）。有紅必須修完才算完成——不得跳過（CLAUDE.md Rule 12）。

- [ ] **Step 2: 前端 build**

Run: `npm run build`
Expected: 成功（本 phase 沒動 JS/CSS 原始碼，此步為保險）。

- [ ] **Step 3: Checkpoint 記錄**

在對話中回報：做了什麼（Task 1–4 commits）、驗證了什麼（測試數、雷達目測是否完成）、剩什麼（若雷達目測待部署後驗證要點名）。
