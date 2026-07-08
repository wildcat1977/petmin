# 交通與清運資訊即時化 + 獨立頁面 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把首頁「交通與清運資訊彙整」卡從手動連結升級為真實抓取解析的即時資料，並新增獨立頁面 `/info`，nav 以此頁取代透明度/統計連結。

**Architecture:** 沿用既有整合層：排程 command → 抓外部來源 → 落地 DB → 前端只讀落地資料（mirror `SyncShelters`/`FetchNcdrAlerts`）。每源接 `DataSourceHealth`、參數進 `config/hazdar.php.sources`。落地只存純陣列/scalar。

**Tech Stack:** Laravel 13 / PHP 8.4 / Livewire 4 / PostgreSQL / Tailwind 4；外部源 HEO 開放資料（免認證）、NCDR CAP、TDX（OAuth2）。

**Spec:** `docs/superpowers/specs/2026-07-08-city-info-realtime-design.md`

## Global Constraints

- 直接在 `main` = 生產（hazdar.puma.taipei）；每個 Task 收尾必跑：`php artisan test --compact <file>` 綠 → `vendor/bin/pint --dirty --format agent` → 若動 blade/新 utility class 則 `npm run build` + `php artisan view:clear`。
- 落地資料只存純陣列/scalar；**禁止 `Cache::put` Eloquent 物件/Collection**。
- 新 PHP class 建立後跑 `composer dump-autoload -o`。
- 每個外部源成功/失敗都要 `DataSourceHealth::recordSuccess/recordFailure`，失敗不得默默跳過且不得 throw 出 command（回 `self::FAILURE`）。
- 測試一律 `Http::fake`，不打真實外部 API；測試涉及 health/快取前先 `Cache::forget` 或用 `array` cache（phpunit.xml `CACHE_STORE=array`，跨 test 方法持續，需顯式 forget）。
- 不動 `resources/views/partials/gtag.blade.php`。
- 慣例：命令 mirror `app/Console/Commands/SyncShelters.php` 與 `FetchNcdrAlerts.php`；健康服務 `app/Services/DataSourceHealth.php`；測試 mirror `tests/Feature/Commands/FetchNcdrAlertsTest.php`。
- 遷移欄位風格見 `database/migrations/2026_07_07_000004_create_info_links_table.php`。
- 目前測試 177 綠。

## File Structure

**Phase 1（無憑證依賴，獨立可上線）**
- Create `database/migrations/2026_07_08_000001_create_pump_stations_table.php`, `app/Models/PumpStation.php`, `database/factories/PumpStationFactory.php`
- Create `app/Console/Commands/FetchPumpStatus.php` + test `tests/Feature/Commands/FetchPumpStatusTest.php`
- Create `database/migrations/2026_07_08_000002_create_floodgates_table.php`, `app/Models/Floodgate.php`, `database/factories/FloodgateFactory.php`
- Create `app/Console/Commands/SyncFloodgates.php` + test `tests/Feature/Commands/SyncFloodgatesTest.php`
- Modify `app/Console/Commands/FetchNcdrAlerts.php`（加抓 AlertType=33）+ test `tests/Feature/Commands/FetchClosureAlertsTest.php`
- Create `app/Support/CityInfo.php`（彙整查詢：pump/floodgate/closure/shelter/transit → 給頁面與卡片共用）
- Create `resources/views/pages/city-info.blade.php`, route in `routes/web.php`, `resources/views/partials/city-info/*.blade.php` 區塊
- Modify `resources/views/livewire/partials/card-info.blade.php`（首頁卡改 parsed 摘要）+ `resources/views/livewire/eoc-dashboard.blade.php`（sharedCardData 加摘要）
- Modify `resources/views/partials/nav-page-links.blade.php`, `resources/views/partials/footer-links.blade.php`
- Modify `config/hazdar.php`, `routes/console.php`, `app/Services/DataSourceHealth.php`
- Modify `tests/Feature/HomeChromeTest.php`（nav 斷言）

**Phase 2（TDX，憑證已驗證）**
- Create `database/migrations/2026_07_08_000003_create_transit_alerts_table.php`, `app/Models/TransitAlert.php`
- Create `app/Services/TdxClient.php`（token 快取 + GET）+ `app/Console/Commands/FetchTransitAlerts.php` + test `tests/Feature/Commands/FetchTransitAlertsTest.php`
- Modify `app/Support/CityInfo.php`（加 transit）, `resources/views/partials/city-info/transit.blade.php`, `card-info.blade.php`

---

## Task 1: pump_stations schema + model + factory

**Files:**
- Create: `database/migrations/2026_07_08_000001_create_pump_stations_table.php`
- Create: `app/Models/PumpStation.php`
- Create: `database/factories/PumpStationFactory.php`
- Test: `tests/Feature/Models/PumpStationTest.php`

**Interfaces:**
- Produces: `PumpStation` model, `pump_stations` table, `PumpStation::factory()`. Columns: `stn_id`(string unique), `name`, `latitude`(decimal), `longitude`(decimal), `pump_num`(int), `gate_num`(int), `pumb_status`(string), `door_status`(?string), `inner_value`(?decimal), `outer_value`(?decimal), `max_level`(?decimal), `observed_at`(?datetime). Const `PumpStation::STATUS_RUNNING = '運轉中'`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pump_stations', function (Blueprint $table) {
            $table->id();
            $table->string('stn_id')->unique();
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('pump_num')->default(0);
            $table->unsignedSmallInteger('gate_num')->default(0);
            $table->string('pumb_status')->nullable();   // 停止 / 運轉中
            $table->string('door_status')->nullable();    // 閘門開啟 / 閘門關閉 / null
            $table->decimal('inner_value', 6, 2)->nullable();
            $table->decimal('outer_value', 6, 2)->nullable();
            $table->decimal('max_level', 6, 2)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pump_stations');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use Database\Factories\PumpStationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PumpStation extends Model
{
    /** @use HasFactory<PumpStationFactory> */
    use HasFactory;

    public const STATUS_RUNNING = '運轉中';

    protected $fillable = [
        'stn_id', 'name', 'latitude', 'longitude', 'pump_num', 'gate_num',
        'pumb_status', 'door_status', 'inner_value', 'outer_value', 'max_level', 'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'inner_value' => 'float',
            'outer_value' => 'float',
            'max_level' => 'float',
            'observed_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return $this->pumb_status === self::STATUS_RUNNING;
    }
}
```

- [ ] **Step 3: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\PumpStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PumpStation> */
class PumpStationFactory extends Factory
{
    protected $model = PumpStation::class;

    public function definition(): array
    {
        return [
            'stn_id' => (string) fake()->unique()->numberBetween(100, 999),
            'name' => fake()->word(),
            'latitude' => fake()->randomFloat(7, 25.0, 25.15),
            'longitude' => fake()->randomFloat(7, 121.45, 121.65),
            'pump_num' => fake()->numberBetween(1, 8),
            'gate_num' => fake()->numberBetween(0, 4),
            'pumb_status' => fake()->randomElement(['停止', '運轉中']),
            'door_status' => fake()->randomElement(['閘門開啟', '閘門關閉', null]),
            'inner_value' => fake()->randomFloat(2, -1, 3),
            'outer_value' => fake()->randomFloat(2, -1, 3),
            'max_level' => fake()->randomFloat(2, 2, 5),
            'observed_at' => now(),
        ];
    }
}
```

- [ ] **Step 4: Write test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\PumpStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PumpStationTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_running_reflects_pumb_status(): void
    {
        $this->assertTrue(PumpStation::factory()->create(['pumb_status' => '運轉中'])->isRunning());
        $this->assertFalse(PumpStation::factory()->create(['pumb_status' => '停止'])->isRunning());
    }
}
```

- [ ] **Step 5: Run + commit**

Run: `php artisan migrate --force && php artisan test --compact tests/Feature/Models/PumpStationTest.php` (expect PASS), then `composer dump-autoload -o`, `vendor/bin/pint --dirty --format agent`.
```bash
git add database/migrations/2026_07_08_000001_create_pump_stations_table.php app/Models/PumpStation.php database/factories/PumpStationFactory.php tests/Feature/Models/PumpStationTest.php
git commit -m "feat: pump_stations 表 + PumpStation model（抽水站即時落地）"
```

---

## Task 2: config.sources.pump + FetchPumpStatus command + health

**Files:**
- Modify: `config/hazdar.php`（sources 加 `pump`）
- Create: `app/Console/Commands/FetchPumpStatus.php`
- Modify: `routes/console.php`（排程）
- Test: `tests/Feature/Commands/FetchPumpStatusTest.php`

**Interfaces:**
- Consumes: `PumpStation` (Task 1), `DataSourceHealth`.
- Produces: command `hazdar:fetch-pump-status`; `config('hazdar.sources.pump')` = `['url'=>..., 'interval_minutes'=>10]`; health source key `'pump'`.

- [ ] **Step 1: Add config** — in `config/hazdar.php` inside the `'sources' => [ ... ]` array (after `'shelters' => [...]`), add:

```php
'pump' => [
    // 臺北市水利處抽水站即時運轉狀態（免認證；spec §11.5 P2 實測 2026-07-08）
    'url' => env('HAZDAR_PUMP_URL', 'https://heopublic.gov.taipei/taipei-heo-api/openapi/pumb/latest'),
    'interval_minutes' => 10,
],
```

- [ ] **Step 2: Write the failing test** (`tests/Feature/Commands/FetchPumpStatusTest.php`)

```php
<?php

namespace Tests\Feature\Commands;

use App\Models\PumpStation;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchPumpStatusTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOk(): void
    {
        Http::fake(['heopublic.gov.taipei/*' => Http::response([
            ['stn_id' => '211', 'stn_name' => '玉成', 'lon' => 121.5825, 'lat' => 25.05234,
                'obs_time' => '2026-07-08 14:15:00', 'inner_value' => '0.02', 'outer_value' => '0.11',
                'pumb_num' => 7, 'door_num' => 3, 'pumb_status' => '停止', 'door_status' => '閘門關閉',
                'max_allowable_water_level' => 3.15],
            ['stn_id' => '213', 'stn_name' => '南京', 'lon' => 121.5722, 'lat' => 25.05069,
                'obs_time' => '2026-07-08 14:00:00', 'inner_value' => '-0.03', 'outer_value' => '0.24',
                'pumb_num' => 3, 'door_num' => 1, 'pumb_status' => '運轉中', 'door_status' => '閘門開啟',
                'max_allowable_water_level' => 3.9],
        ])]);
    }

    public function test_pump_stations_are_ingested_and_upserted(): void
    {
        $this->fakeOk();
        $this->artisan('hazdar:fetch-pump-status')->assertSuccessful();

        $this->assertSame(2, PumpStation::count());
        $yucheng = PumpStation::where('stn_id', '211')->sole();
        $this->assertSame('玉成', $yucheng->name);
        $this->assertSame('停止', $yucheng->pumb_status);
        $this->assertSame('閘門關閉', $yucheng->door_status);
        $this->assertEqualsWithDelta(25.05234, $yucheng->latitude, 0.00001);
        $this->assertSame('2026-07-08 14:15:00', $yucheng->observed_at->timezone('Asia/Taipei')->format('Y-m-d H:i:s'));
        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('pump')['status']);

        // 重跑不重複（依 stn_id upsert），且更新狀態
        Http::fake(['heopublic.gov.taipei/*' => Http::response([
            ['stn_id' => '211', 'stn_name' => '玉成', 'lon' => 121.5825, 'lat' => 25.05234,
                'obs_time' => '2026-07-08 15:00:00', 'inner_value' => '0.5', 'outer_value' => '0.9',
                'pumb_num' => 7, 'door_num' => 3, 'pumb_status' => '運轉中', 'door_status' => '閘門開啟',
                'max_allowable_water_level' => 3.15],
        ])]);
        $this->artisan('hazdar:fetch-pump-status')->assertSuccessful();
        $this->assertSame(2, PumpStation::count());
        $this->assertSame('運轉中', PumpStation::where('stn_id', '211')->sole()->pumb_status);
    }

    public function test_failure_degrades_health_without_throwing(): void
    {
        Http::fake(['heopublic.gov.taipei/*' => Http::response('oops', 503)]);
        $this->artisan('hazdar:fetch-pump-status')->assertFailed();

        $status = app(DataSourceHealth::class)->status('pump');
        $this->assertSame(DataSourceHealth::STATUS_DOWN, $status['status']);
        $this->assertNotNull($status['last_error']);
    }
}
```

- [ ] **Step 3: Run to verify it fails** — `php artisan test --compact tests/Feature/Commands/FetchPumpStatusTest.php` → FAIL（command 不存在）。

- [ ] **Step 4: Write the command** (`app/Console/Commands/FetchPumpStatus.php`)

```php
<?php

namespace App\Console\Commands;

use App\Models\PumpStation;
use App\Services\DataSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 抽水站即時運轉狀態（spec §11.5 P2，每 10 分鐘）。
 * 來源：臺北市水利處 HEO 開放資料（免認證），依 stn_id upsert。
 */
class FetchPumpStatus extends Command
{
    protected $signature = 'hazdar:fetch-pump-status';

    protected $description = '拉取臺北市水利處抽水站即時狀態並落地 pump_stations';

    public function handle(DataSourceHealth $health): int
    {
        $config = config('hazdar.sources.pump');

        try {
            $response = Http::timeout(15)->get($config['url']);
            if (! $response->successful()) {
                throw new RuntimeException("HEO pump HTTP {$response->status()}");
            }

            $rows = $response->json();
            if (! is_array($rows) || $rows === []) {
                throw new RuntimeException('HEO pump 回應無資料');
            }

            $upserted = 0;
            foreach ($rows as $row) {
                if (empty($row['stn_id'])) {
                    continue;
                }
                PumpStation::updateOrCreate(
                    ['stn_id' => (string) $row['stn_id']],
                    [
                        'name' => mb_substr((string) ($row['stn_name'] ?? ''), 0, 255),
                        'latitude' => isset($row['lat']) ? (float) $row['lat'] : null,
                        'longitude' => isset($row['lon']) ? (float) $row['lon'] : null,
                        'pump_num' => (int) ($row['pumb_num'] ?? 0),
                        'gate_num' => (int) ($row['door_num'] ?? 0),
                        'pumb_status' => $row['pumb_status'] ?? null,
                        'door_status' => $row['door_status'] ?? null,
                        'inner_value' => is_numeric($row['inner_value'] ?? null) ? (float) $row['inner_value'] : null,
                        'outer_value' => is_numeric($row['outer_value'] ?? null) ? (float) $row['outer_value'] : null,
                        'max_level' => is_numeric($row['max_allowable_water_level'] ?? null) ? (float) $row['max_allowable_water_level'] : null,
                        'observed_at' => $this->parseObservedAt($row['obs_time'] ?? null),
                    ],
                );
                $upserted++;
            }

            $health->recordSuccess('pump');
            $this->info("Pump stations: {$upserted} upserted / ".PumpStation::count().' total');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('hazdar: pump fetch failed', ['error' => $e->getMessage()]);
            $health->recordFailure('pump', $e->getMessage());
            $this->error("Pump fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function parseObservedAt(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $value, 'Asia/Taipei');
        } catch (Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 5: Add schedule** — in `routes/console.php` after the `hazdar:sync-shelters` line add:

```php
Schedule::command('hazdar:fetch-pump-status')->everyTenMinutes()->runInBackground();
```

- [ ] **Step 6: Run + commit** — `composer dump-autoload -o`, `php artisan test --compact tests/Feature/Commands/FetchPumpStatusTest.php` (PASS), `vendor/bin/pint --dirty --format agent`.
```bash
git add config/hazdar.php app/Console/Commands/FetchPumpStatus.php routes/console.php tests/Feature/Commands/FetchPumpStatusTest.php
git commit -m "feat: hazdar:fetch-pump-status 抓抽水站即時狀態落地 + 健康燈"
```

---

## Task 3: floodgates（靜態位置）schema + model + SyncFloodgates command

**Files:**
- Create: `database/migrations/2026_07_08_000002_create_floodgates_table.php`, `app/Models/Floodgate.php`, `database/factories/FloodgateFactory.php`
- Create: `app/Console/Commands/SyncFloodgates.php`
- Modify: `config/hazdar.php`（sources 加 `floodgates`）, `routes/console.php`
- Test: `tests/Feature/Commands/SyncFloodgatesTest.php`

**Interfaces:**
- Produces: `Floodgate` model（`external_key` unique, `name`, `description`, `latitude`, `longitude`）；command `hazdar:sync-floodgates`；`config('hazdar.sources.floodgates')` = `['url'=>'https://data.taipei/api/v1/dataset/%s?scope=resourceAquire&limit=1000', 'resource_id'=>'47cffd30-3527-45af-b709-6f76772e3cfb', 'interval_minutes'=>1440]`.

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floodgates', function (Blueprint $table) {
            $table->id();
            $table->string('external_key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floodgates');
    }
};
```

- [ ] **Step 2: Model**

```php
<?php

namespace App\Models;

use Database\Factories\FloodgateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Floodgate extends Model
{
    /** @use HasFactory<FloodgateFactory> */
    use HasFactory;

    protected $fillable = ['external_key', 'name', 'description', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }
}
```

- [ ] **Step 3: Factory** — mirror `PumpStationFactory` shape: `external_key => fake()->unique()->slug()`, `name => fake()->word()`, `description => fake()->sentence()`, lat/lng Taipei range.

```php
<?php

namespace Database\Factories;

use App\Models\Floodgate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Floodgate> */
class FloodgateFactory extends Factory
{
    protected $model = Floodgate::class;

    public function definition(): array
    {
        return [
            'external_key' => fake()->unique()->slug(),
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'latitude' => fake()->randomFloat(7, 24.98, 25.15),
            'longitude' => fake()->randomFloat(7, 121.45, 121.65),
        ];
    }
}
```

- [ ] **Step 4: config + schedule** — add to `config/hazdar.php` sources:

```php
'floodgates' => [
    // 臺北市疏散門靜態位置（data.taipei，已 WGS84，36 座；即時狀態端點不可達故只放位置）
    'url' => env('TAIPEI_FLOODGATE_URL', 'https://data.taipei/api/v1/dataset/%s?scope=resourceAquire&limit=1000'),
    'resource_id' => env('TAIPEI_FLOODGATE_RESOURCE_ID', '47cffd30-3527-45af-b709-6f76772e3cfb'),
    'interval_minutes' => 1440,
],
```
`routes/console.php`: `Schedule::command('hazdar:sync-floodgates')->dailyAt('05:20')->runInBackground();`

- [ ] **Step 5: Failing test** (`tests/Feature/Commands/SyncFloodgatesTest.php`)

```php
<?php

namespace Tests\Feature\Commands;

use App\Models\Floodgate;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncFloodgatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_floodgates_are_ingested_with_coords(): void
    {
        Http::fake(['data.taipei/*' => Http::response(['result' => ['results' => [
            ['riverside_park' => '景美河濱公園', 'name' => '景1,育英', 'description' => '文山區育英街底',
                'longitude' => '121.5369669', 'latitude' => '24.98947221'],
            ['riverside_park' => '大稻埕', 'name' => '5號水門', 'description' => '民權西路底',
                'longitude' => '121.5100', 'latitude' => '25.0600'],
        ]]]]);

        $this->artisan('hazdar:sync-floodgates')->assertSuccessful();

        $this->assertSame(2, Floodgate::count());
        $gate = Floodgate::where('name', '景1,育英')->sole();
        $this->assertEqualsWithDelta(24.98947221, $gate->latitude, 0.00001);
        $this->assertEqualsWithDelta(121.5369669, $gate->longitude, 0.00001);
        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('floodgates')['status']);

        // 重跑 upsert 不重複
        $this->artisan('hazdar:sync-floodgates')->assertSuccessful();
        $this->assertSame(2, Floodgate::count());
    }

    public function test_failure_degrades_health(): void
    {
        Http::fake(['data.taipei/*' => Http::response('x', 500)]);
        $this->artisan('hazdar:sync-floodgates')->assertFailed();
        $this->assertSame(DataSourceHealth::STATUS_DOWN, app(DataSourceHealth::class)->status('floodgates')['status']);
    }
}
```

- [ ] **Step 6: Command** (`app/Console/Commands/SyncFloodgates.php`) — mirror `SyncShelters` fetch, `external_key = riverside_park.'_'.name`, upsert.

```php
<?php

namespace App\Console\Commands;

use App\Models\Floodgate;
use App\Services\DataSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** 臺北市疏散門靜態位置同步（spec §11.5 P2，daily；即時狀態端點不可達，只放位置）。 */
class SyncFloodgates extends Command
{
    protected $signature = 'hazdar:sync-floodgates';

    protected $description = '同步 data.taipei 疏散門位置並落地 floodgates';

    public function handle(DataSourceHealth $health): int
    {
        $config = config('hazdar.sources.floodgates');

        try {
            if (empty($config['resource_id'])) {
                throw new RuntimeException('TAIPEI_FLOODGATE_RESOURCE_ID 未設定');
            }
            $response = Http::timeout(15)->get(sprintf($config['url'], $config['resource_id']));
            if (! $response->successful()) {
                throw new RuntimeException("data.taipei HTTP {$response->status()}");
            }
            $rows = data_get($response->json(), 'result.results', []);
            if (empty($rows)) {
                throw new RuntimeException('data.taipei 回應無資料列');
            }

            $upserted = 0;
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                $park = trim((string) ($row['riverside_park'] ?? ''));
                if ($name === '') {
                    continue;
                }
                Floodgate::updateOrCreate(
                    ['external_key' => ($park !== '' ? $park.'_' : '').$name],
                    [
                        'name' => mb_substr($name, 0, 255),
                        'description' => trim((string) ($row['description'] ?? '')) ?: null,
                        'latitude' => is_numeric($row['latitude'] ?? null) ? (float) $row['latitude'] : null,
                        'longitude' => is_numeric($row['longitude'] ?? null) ? (float) $row['longitude'] : null,
                    ],
                );
                $upserted++;
            }

            $health->recordSuccess('floodgates');
            $this->info("Floodgates synced: {$upserted} / ".Floodgate::count().' total');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('hazdar: floodgate sync failed', ['error' => $e->getMessage()]);
            $health->recordFailure('floodgates', $e->getMessage());
            $this->error("Floodgate sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
```

- [ ] **Step 7: Run + commit** — migrate, `composer dump-autoload -o`, test PASS, pint.
```bash
git add database/migrations/2026_07_08_000002_create_floodgates_table.php app/Models/Floodgate.php database/factories/FloodgateFactory.php app/Console/Commands/SyncFloodgates.php config/hazdar.php routes/console.php tests/Feature/Commands/SyncFloodgatesTest.php
git commit -m "feat: hazdar:sync-floodgates 同步疏散門位置（靜態，36 座）"
```

---

## Task 4: 停班停課 — 擴充 FetchNcdrAlerts 加抓 AlertType=33

**Files:**
- Modify: `app/Console/Commands/FetchNcdrAlerts.php`
- Modify: `config/hazdar.php`（`sources.ncdr_alerts.closure_feed_url`）
- Test: `tests/Feature/Commands/FetchClosureAlertsTest.php`

**Interfaces:**
- Consumes: `ExternalAlert`（既有）, `DataSourceHealth`.
- Produces: `external_alerts` 內 `alert_type='停班停課'` 的列（`covers_taipei` 已判定）；供 CityInfo（Task 5）以 `ExternalAlert::where('alert_type','停班停課')->active()->coversTaipei()` 讀取。

**設計**：現有 `handle()` 只抓一條 feed。重構抽出 `ingestFeed(string $url, ?string $alertTypeOverride, int &$detailBudget): int`（把現有第 34–83 行的抓取+去重+入庫邏輯搬進去），`handle()` 先呼叫主 feed（override=null），再呼叫 closure feed（override='停班停課'）。`ExternalAlert::create` 的 `alert_type` 改為 `$alertTypeOverride ?? $entry['alert_type']`。健康燈維持記 `'ncdr_alerts'`（同源）。

- [ ] **Step 1: Config** — 在 `config/hazdar.php` 的 `sources.ncdr_alerts` 陣列加一行：

```php
'closure_feed_url' => env('NCDR_CLOSURE_FEED_URL', 'https://alerts.ncdr.nat.gov.tw/RssAtomFeed.ashx?AlertType=33'),
```

- [ ] **Step 2: Failing test** (`tests/Feature/Commands/FetchClosureAlertsTest.php`)

```php
<?php

namespace Tests\Feature\Commands;

use App\Models\ExternalAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchClosureAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function closureFeed(string $areaDesc = '臺北市'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:cap="urn:oasis:names:tc:emergency:cap:1.1">
  <entry>
    <id>CLOSURE_TPE_1</id>
    <title>停班停課</title>
    <author><name>行政院人事行政總處</name></author>
    <link rel="alternate" href="https://alerts.test/cap/closure.cap" />
    <summary type="html">臺北市明天停止上班、停止上課。</summary>
    <cap:effective>2026/7/8 下午 06:00:00</cap:effective>
    <cap:expires>2026/7/9 下午 06:00:00</cap:expires>
  </entry>
</feed>
XML;
    }

    private function capXml(string $areaDesc): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
  <info><severity>Severe</severity><area><areaDesc>{$areaDesc}</areaDesc></area></info>
</alert>
XML;
    }

    public function test_closure_alerts_land_with_closure_type_and_taipei_flag(): void
    {
        Http::fake([
            'alerts.ncdr.nat.gov.tw/RssAtomFeed.ashx?AlertType=33' => Http::response($this->closureFeed()),
            'alerts.ncdr.nat.gov.tw/*' => Http::response('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>'),
            'alerts.test/cap/closure.cap' => Http::response($this->capXml('臺北市')),
        ]);

        $this->artisan('hazdar:fetch-ncdr-alerts')->assertSuccessful();

        $alert = ExternalAlert::where('alert_type', '停班停課')->sole();
        $this->assertTrue($alert->covers_taipei);
        $this->assertStringContainsString('停止上班', $alert->headline);
    }
}
```

Note：`Http::fake` 以最specific的 pattern 先列（含 query 的 AlertType=33），一般 feed_url 用萬用 pattern 回空 feed，避免主 feed 干擾。

- [ ] **Step 3: Run to fail** — `php artisan test --compact tests/Feature/Commands/FetchClosureAlertsTest.php` → FAIL（無 停班停課 列）。

- [ ] **Step 4: Refactor command** — 把 `handle()` 內「抓 feed → 去重 → 逐筆 create」重構為 `ingestFeed(string $url, ?string $alertTypeOverride, int &$detailBudget): int`，`ExternalAlert::create([... 'alert_type' => $alertTypeOverride ?? $entry['alert_type'] ...])`。`handle()` 改為：

```php
public function handle(DataSourceHealth $health): int
{
    $config = config('hazdar.sources.ncdr_alerts');

    try {
        $detailBudget = (int) $config['max_detail_fetches'];
        $created = $this->ingestFeed($config['feed_url'], null, $detailBudget);
        if (! empty($config['closure_feed_url'])) {
            $created += $this->ingestFeed($config['closure_feed_url'], '停班停課', $detailBudget);
        }

        $health->recordSuccess('ncdr_alerts');
        $this->info("NCDR alerts: {$created} new");

        return self::SUCCESS;
    } catch (Throwable $e) {
        Log::error('hazdar: NCDR fetch failed', ['error' => $e->getMessage()]);
        $health->recordFailure('ncdr_alerts', $e->getMessage());
        $this->error("NCDR fetch failed: {$e->getMessage()}");

        return self::FAILURE;
    }
}
```
`ingestFeed` 內容 = 現行 handle 第 34–83 行（`Http::get($url)` … `foreach ($entries …) { … ExternalAlert::create([...]) … }`），把 `$entry['alert_type']` 換成 `$alertTypeOverride ?? $entry['alert_type']`，回傳 `$created`。

- [ ] **Step 5: Run existing + new** — `php artisan test --compact tests/Feature/Commands/FetchNcdrAlertsTest.php tests/Feature/Commands/FetchClosureAlertsTest.php`（兩者皆 PASS；確認重構沒破壞既有）。pint.

- [ ] **Step 6: Commit**
```bash
git add app/Console/Commands/FetchNcdrAlerts.php config/hazdar.php tests/Feature/Commands/FetchClosureAlertsTest.php
git commit -m "feat: FetchNcdrAlerts 加抓 AlertType=33 停班停課，落地 external_alerts"
```

---

## Task 5: CityInfo 彙整 + /info 獨立頁面

**Files:**
- Create: `app/Support/CityInfo.php`
- Create: `resources/views/pages/city-info.blade.php`
- Modify: `routes/web.php`（route `/info` name `city-info`）
- Test: `tests/Feature/CityInfoPageTest.php`

**Interfaces:**
- Consumes: `PumpStation`, `Floodgate`, `ExternalAlert`, `Shelter`, `InfoLink`.
- Produces: `App\Support\CityInfo` with static methods `closure(): ?ExternalAlert`, `pumpStations(): Collection`, `floodgates(): Collection`, `openShelterCount(): int`, `manualSanitation(): Collection`（`InfoLink` where section=sanitation）, `transitBySystem(): array`（Phase 2 前回空陣列）; route `city-info`.

- [ ] **Step 1: CityInfo support class**

```php
<?php

namespace App\Support;

use App\Models\ExternalAlert;
use App\Models\Floodgate;
use App\Models\InfoLink;
use App\Models\PumpStation;
use App\Models\Shelter;
use Illuminate\Support\Collection;

/** /info 頁面與首頁卡共用的資料彙整（只讀落地資料）。 */
class CityInfo
{
    /** 目前有效且涵蓋台北市的停班停課（無則 null → 顯示正常上班上課）。 */
    public static function closure(): ?ExternalAlert
    {
        return ExternalAlert::where('alert_type', '停班停課')
            ->active()->coversTaipei()
            ->latest('effective_at')
            ->first();
    }

    /** @return Collection<int, PumpStation> */
    public static function pumpStations(): Collection
    {
        return PumpStation::orderBy('name')->get();
    }

    public static function runningPumpCount(): int
    {
        return PumpStation::where('pumb_status', PumpStation::STATUS_RUNNING)->count();
    }

    /** @return Collection<int, Floodgate> */
    public static function floodgates(): Collection
    {
        return Floodgate::whereNotNull('latitude')->orderBy('name')->get();
    }

    public static function openShelterCount(): int
    {
        return Shelter::where('is_open', true)->count();
    }

    /** @return Collection<int, InfoLink> 清運與環保局手動連結（無 API，維持手動） */
    public static function manualSanitation(): Collection
    {
        return InfoLink::where('section', InfoLink::SECTION_SANITATION)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /** @return array<string, list<array<string,mixed>>> Phase 2 才有內容 */
    public static function transitBySystem(): array
    {
        return [];
    }
}
```

- [ ] **Step 2: Route** — in `routes/web.php` near the `news` route add:

```php
Route::view('/info', 'pages.city-info')->name('city-info');
```
（view 內用 `App\Support\CityInfo` 靜態查詢，不需 controller。）

- [ ] **Step 3: Failing test** (`tests/Feature/CityInfoPageTest.php`)

```php
<?php

namespace Tests\Feature;

use App\Models\ExternalAlert;
use App\Models\PumpStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CityInfoPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_normal_state_when_no_data(): void
    {
        $this->get('/info')->assertOk()->assertSee('正常上班上課');
    }

    public function test_page_shows_active_closure_and_running_pumps(): void
    {
        ExternalAlert::create([
            'source' => 'DGPA', 'cap_id' => 'C1', 'alert_type' => '停班停課',
            'headline' => '臺北市明天停止上班、停止上課', 'covers_taipei' => true,
            'effective_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        PumpStation::factory()->create(['name' => '玉成', 'pumb_status' => '運轉中']);

        $this->get('/info')->assertOk()
            ->assertSee('停止上班')
            ->assertSee('玉成');
    }
}
```

- [ ] **Step 4: Page view** (`resources/views/pages/city-info.blade.php`) — 套 layout + 共用 header/footer；上而下區塊。Badge helper inline。

```blade
@extends('layouts.app')
@section('title', '交通與清運資訊 · Hazdar')
@section('content')
@php
    use App\Support\CityInfo;
    $closure = CityInfo::closure();
    $pumps = CityInfo::pumpStations();
    $gates = CityInfo::floodgates();
    $sanitation = CityInfo::manualSanitation();
    $openShelters = CityInfo::openShelterCount();
    $transit = CityInfo::transitBySystem();
@endphp
<div class="min-h-dvh bg-radar-bg text-slate-200">
    @include('partials.site-header')
    <div class="mx-auto max-w-4xl px-4 py-8">
        <h1 class="mb-6 text-2xl font-bold text-white">🚦 交通與清運資訊</h1>

        {{-- 停班停課 --}}
        @include('partials.city-info.closure', ['closure' => $closure])

        {{-- 大眾交通（Phase 2 才有內容；空則不顯示整段）--}}
        @include('partials.city-info.transit', ['transit' => $transit])

        {{-- 水門與抽水站 --}}
        @include('partials.city-info.water', ['pumps' => $pumps, 'gates' => $gates])

        {{-- 清運與環保局（手動）--}}
        @include('partials.city-info.sanitation', ['links' => $sanitation])

        {{-- 避難收容所 --}}
        <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
            <h2 class="mb-2 text-lg font-semibold text-slate-200">🏫 避難收容所</h2>
            <p class="text-sm text-slate-400">目前開設 <span class="font-bold text-emerald-400">{{ $openShelters }}</span> 處 ·
                <a href="{{ route('home') }}" class="text-cyan-300 underline underline-offset-2">在地圖查看 →</a></p>
        </section>
    </div>
    <div class="border-t border-white/10 px-4 py-6">@include('partials.footer-links')</div>
</div>
@endsection
```

Create the four partials under `resources/views/partials/city-info/`:

`closure.blade.php`:
```blade
@if ($closure)
    <section class="mb-6 rounded-xl border border-rose-500/40 bg-rose-500/10 p-4">
        <h2 class="text-lg font-bold text-rose-300">⚠️ 停班停課</h2>
        <p class="mt-1 text-sm leading-relaxed text-slate-100">{{ $closure->headline }}</p>
        @if ($closure->effective_at)
            <p class="mt-1 text-xs text-slate-400">生效：{{ $closure->effective_at->timezone('Asia/Taipei')->format('m/d H:i') }}</p>
        @endif
    </section>
@else
    <section class="mb-6 rounded-xl border border-white/10 bg-white/5 p-4">
        <h2 class="text-lg font-semibold text-slate-300">停班停課</h2>
        <p class="mt-1 text-sm text-emerald-400">目前正常上班上課</p>
    </section>
@endif
```

`water.blade.php`（抽水站狀態徽章 + 疏散門位置數）:
```blade
<section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
    <h2 class="mb-3 text-lg font-semibold text-slate-200">💧 水門與抽水站</h2>
    @forelse ($pumps as $p)
        <div class="flex items-center justify-between border-b border-white/5 py-1.5 text-sm last:border-0">
            <span class="text-slate-300">{{ $p->name }}</span>
            <span class="flex items-center gap-2">
                @if ($p->door_status)
                    <span class="text-xs text-slate-500">{{ $p->door_status }}</span>
                @endif
                <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $p->isRunning() ? 'bg-yellow-500/20 text-yellow-300' : 'bg-slate-500/20 text-slate-400' }}">
                    {{ $p->pumb_status ?? '—' }}
                </span>
            </span>
        </div>
    @empty
        <p class="text-sm text-slate-500">目前無抽水站資料</p>
    @endforelse
    @if ($pumps->isNotEmpty() && $pumps->first()->observed_at)
        <p class="mt-2 text-xs text-slate-600">資料更新於 {{ $pumps->first()->observed_at->timezone('Asia/Taipei')->format('m/d H:i') }}</p>
    @endif
    <p class="mt-2 text-xs text-slate-500">疏散門位置 {{ $gates->count() }} 座（詳見地圖）</p>
</section>
```

`sanitation.blade.php`（手動 InfoLink；空則整段略過）:
```blade
@if ($links->isNotEmpty())
    <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
        <h2 class="mb-3 text-lg font-semibold text-slate-200">🗑️ 清運與環保局</h2>
        @foreach ($links as $l)
            <div class="flex items-center justify-between border-b border-white/5 py-1.5 text-sm last:border-0">
                <a href="{{ $l->url }}" class="text-cyan-300 hover:underline">{{ $l->label }}</a>
                @if ($l->status_text)<span class="text-xs text-slate-400">{{ $l->status_text }}</span>@endif
            </div>
        @endforeach
    </section>
@endif
```

`transit.blade.php`（Phase 1 為空殼，`@if (! empty($transit))`；Phase 2 Task 10 填內容）:
```blade
@if (! empty($transit))
    <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
        <h2 class="mb-3 text-lg font-semibold text-slate-200">🚇 大眾交通</h2>
        {{-- Phase 2 Task 10 填入各系統狀態 --}}
    </section>
@endif
```

- [ ] **Step 5: Run + build + commit** — `php artisan test --compact tests/Feature/CityInfoPageTest.php`（PASS）, `npm run build`, `php artisan view:clear`, pint.
```bash
git add app/Support/CityInfo.php resources/views/pages/city-info.blade.php resources/views/partials/city-info/ routes/web.php tests/Feature/CityInfoPageTest.php
git commit -m "feat: /info 交通與清運資訊獨立頁面（停班停課/抽水站/疏散門/清運/收容所）"
```

---

## Task 6: 首頁卡改 parsed 摘要

**Files:**
- Modify: `resources/views/livewire/eoc-dashboard.blade.php`（`sharedCardData()` 加 city-info 摘要 keys）
- Modify: `resources/views/livewire/partials/card-info.blade.php`（顯示摘要 + 連 /info）
- Test: `tests/Feature/InfoLinksBoardTest.php`（改為斷言摘要 + /info 連結）

**Interfaces:**
- Consumes: `App\Support\CityInfo`.
- Produces: card-info 顯示「抽水站 N 運轉中 / 停班停課狀態」+「查看完整 →」連 `route('city-info')`。

- [ ] **Step 1: sharedCardData** — 在 `eoc-dashboard.blade.php` 的 `sharedCardData()`（含 `'infoLinks' => $this->computeInfoLinks()` 那個陣列）加：

```php
'cityInfoSummary' => [
    'runningPumps' => \App\Support\CityInfo::runningPumpCount(),
    'closureActive' => \App\Support\CityInfo::closure() !== null,
    'openShelters' => \App\Support\CityInfo::openShelterCount(),
],
```

- [ ] **Step 2: card-info 摘要** — 改寫 `resources/views/livewire/partials/card-info.blade.php`：**移除**原本逐區塊 infoLinks 連結清單（完整內容已移到 `/info`），整卡改為下方 parsed 摘要 + 連結：

```blade
@php $s = $cityInfoSummary ?? ['runningPumps' => 0, 'closureActive' => false, 'openShelters' => 0]; @endphp
<div class="space-y-1.5 text-sm">
    <div class="flex items-center justify-between">
        <span class="text-slate-400">停班停課</span>
        <span class="{{ $s['closureActive'] ? 'text-rose-300 font-semibold' : 'text-emerald-400' }}">
            {{ $s['closureActive'] ? '已發布' : '正常' }}
        </span>
    </div>
    <div class="flex items-center justify-between">
        <span class="text-slate-400">抽水站運轉中</span>
        <span class="text-yellow-300 font-semibold">{{ $s['runningPumps'] }}</span>
    </div>
    <div class="flex items-center justify-between">
        <span class="text-slate-400">收容所開設</span>
        <span class="text-emerald-400 font-semibold">{{ $s['openShelters'] }}</span>
    </div>
</div>
<a href="{{ route('city-info') }}" class="mt-3 block text-center text-xs text-cyan-300 hover:underline">查看完整交通與清運資訊 →</a>
```

- [ ] **Step 3: Update test** — `tests/Feature/InfoLinksBoardTest.php` 改斷言首頁出現 `route('city-info')` 連結與摘要標籤（「抽水站運轉中」）。若原測試斷言舊 infoLinks 細節，改成新摘要語意（Rule 9：測試意圖＝卡片顯示 parsed 摘要並連到 /info）。

```php
public function test_home_card_shows_parsed_summary_and_links_to_city_info(): void
{
    \App\Models\PumpStation::factory()->count(2)->create(['pumb_status' => '運轉中']);
    $this->withHeader('Accept-Language', 'zh-TW')->get('/')
        ->assertOk()
        ->assertSee('抽水站運轉中')
        ->assertSee(route('city-info'), false);
}
```

- [ ] **Step 4: Run + build + commit** — test PASS, `npm run build`, `view:clear`, pint.
```bash
git add resources/views/livewire/eoc-dashboard.blade.php resources/views/livewire/partials/card-info.blade.php tests/Feature/InfoLinksBoardTest.php
git commit -m "feat: 首頁交通與清運卡改 parsed 摘要 + 連 /info"
```

---

## Task 7: nav 換連結 + footer 不因新源變擠 + HomeChrome 斷言

**Files:**
- Modify: `resources/views/partials/nav-page-links.blade.php`
- Modify: `resources/views/partials/footer-links.blade.php`
- Modify: `app/Services/DataSourceHealth.php`（`SOURCE_LABELS` 維持原 4 個，作為 footer 顯示白名單）
- Test: `tests/Feature/HomeChromeTest.php`

**Interfaces:** nav 出現 `route('city-info')`、不出現 `route('transparency')`/`route('stats')`；footer 燈維持原 4 源（不因 config 新增 pump/floodgates 而變 7 個）。

- [ ] **Step 1: nav-page-links** — 把第 14–15 行（transparency、stats）換成一行 city-info：

```blade
<a href="{{ route('news') }}" class="{{ $navPageLinkCls('news') }}">{{ __('最新消息') }}</a>
<a href="{{ route('reports') }}" class="{{ $navPageLinkCls('reports') }}">{{ __('通報清單') }}</a>
<a href="{{ route('city-info') }}" class="{{ $navPageLinkCls('city-info') }}">{{ __('交通與清運資訊') }}</a>
```
（`route('transparency')`/`route('stats')` 路由定義**保留**，僅移除 nav 連結。）

- [ ] **Step 2: footer 白名單** — `footer-links.blade.php` 第 8 行改為只跑 `SOURCE_LABELS`（顯示白名單），避免新增的 pump/floodgates 源灌進 footer：

```php
$footerHealth = collect(\App\Services\DataSourceHealth::SOURCE_LABELS)
    ->map(fn ($label, $source) => [...app(\App\Services\DataSourceHealth::class)->status($source), 'label' => $label]);
```
`DataSourceHealth::SOURCE_LABELS` **維持原 4 個**（cwa_observations/ncdr_alerts/radar_echo/shelters）；pump/floodgates/transit 不加入 → footer 仍 4 燈，新源健康只在 /info 呈現。

- [ ] **Step 3: HomeChrome 斷言** — 更新 `HomeChromeTest`：
  - `test_top_nav_shares_page_links_with_secondary_pages`：把 `assertSee(route('transparency'))`/`assertSee(route('stats'))` 改為 `assertDontSee(route('transparency'), false)` + `assertDontSee(route('stats'), false)` + `assertSee(route('city-info'), false)`。
  - `test_mobile_has_hamburger_menu_with_full_link_set`：把用來計數的 `route('stats')` 換成 `route('city-info')`（桌機 nav + 手機面板 = 2 次）。

```php
public function test_top_nav_uses_city_info_not_transparency_stats(): void
{
    $this->withHeader('Accept-Language', 'zh-TW')->get('/')
        ->assertOk()
        ->assertSee(route('city-info'), false)
        ->assertDontSee(route('transparency'), false)
        ->assertDontSee(route('stats'), false);
}
```

- [ ] **Step 4: Run + build + commit** — `php artisan test --compact tests/Feature/HomeChromeTest.php`（PASS）, `npm run build`, `view:clear`, pint。全套 `php artisan test --compact`（確認 nav 改動沒破壞別的）。
```bash
git add resources/views/partials/nav-page-links.blade.php resources/views/partials/footer-links.blade.php app/Services/DataSourceHealth.php tests/Feature/HomeChromeTest.php
git commit -m "feat: nav 以交通與清運資訊取代透明度/統計；footer 燈維持原 4 源"
```

**== Phase 1 完成：/info 頁面 + 首頁卡 + nav 皆真實資料，可獨立上線驗收 ==**

---

## Task 8: transit_alerts schema + model（Phase 2）

**Files:**
- Create: `database/migrations/2026_07_08_000003_create_transit_alerts_table.php`, `app/Models/TransitAlert.php`

**Interfaces:** `TransitAlert`（`system` metro|gondola|bus, `identifier`, `scope_name`, `status` 0/1/2, `cause`, `effect`, `title`, `description`, `effective_at`, `expires_at`）；consts `SYSTEM_METRO='metro'`,`SYSTEM_GONDOLA='gondola'`,`SYSTEM_BUS='bus'`, `STATUS_NORMAL=1`.

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transit_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('system');            // metro / gondola / bus
            $table->string('identifier')->nullable();
            $table->string('scope_name')->nullable(); // 線/路名
            $table->unsignedTinyInteger('status')->default(1); // 0 全停 / 1 正常 / 2 異常
            $table->unsignedSmallInteger('cause')->nullable();
            $table->unsignedSmallInteger('effect')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('system');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transit_alerts');
    }
};
```

- [ ] **Step 2: Model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransitAlert extends Model
{
    public const SYSTEM_METRO = 'metro';
    public const SYSTEM_GONDOLA = 'gondola';
    public const SYSTEM_BUS = 'bus';
    public const STATUS_NORMAL = 1;

    protected $fillable = [
        'system', 'identifier', 'scope_name', 'status', 'cause', 'effect',
        'title', 'description', 'effective_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['status' => 'integer', 'cause' => 'integer', 'effect' => 'integer',
            'effective_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
```

- [ ] **Step 3: migrate + dump-autoload + commit**
```bash
git add database/migrations/2026_07_08_000003_create_transit_alerts_table.php app/Models/TransitAlert.php
git commit -m "feat: transit_alerts 表 + TransitAlert model（TDX 交通警示落地）"
```

---

## Task 9: TdxClient + FetchTransitAlerts（credential-gated）

**Files:**
- Create: `app/Services/TdxClient.php`, `app/Console/Commands/FetchTransitAlerts.php`
- Modify: `config/hazdar.php`（`sources.transit`）, `routes/console.php`
- Test: `tests/Feature/Commands/FetchTransitAlertsTest.php`

**Interfaces:**
- `TdxClient::token(): ?string`（POST client_credentials，快取 24h 純字串，401/失敗回 null）; `TdxClient::get(string $path): ?array`。
- `config('hazdar.sources.transit')` = `['tdx_client_id'=>env, 'tdx_client_secret'=>env, 'token_url'=>..., 'base_url'=>..., 'endpoints'=>['metro'=>'/v2/Rail/Metro/Alert/TRTC','gondola'=>'/v2/Rail/Metro/Alert/TRTCMG','bus'=>'/v2/Bus/Alert/City/Taipei'], 'interval_minutes'=>5]`.
- **啟用條件**：`tdx_client_id && tdx_client_secret` 皆非空；否則 command 跳過、`recordFailure('transit','未設定 TDX 憑證')`？→ 不，用**不記為 down**：以 `$this->info` 標示 skipped 並直接回 `self::SUCCESS`，**不建立資料、不報 error、不把健康燈弄紅**。

- [ ] **Step 1: Config**

```php
'transit' => [
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
```

- [ ] **Step 2: TdxClient**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** TDX OAuth2 client_credentials；token 快取為純字串（禁快取物件），過期/401 重取。 */
class TdxClient
{
    private const TOKEN_CACHE_KEY = 'hazdar:tdx:token';

    public function configured(): bool
    {
        $c = config('hazdar.sources.transit');

        return ! empty($c['tdx_client_id']) && ! empty($c['tdx_client_secret']);
    }

    public function token(bool $fresh = false): ?string
    {
        if (! $fresh) {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $c = config('hazdar.sources.transit');
        try {
            $resp = Http::asForm()->timeout(15)->post($c['token_url'], [
                'grant_type' => 'client_credentials',
                'client_id' => $c['tdx_client_id'],
                'client_secret' => $c['tdx_client_secret'],
            ]);
            $token = (string) data_get($resp->json(), 'access_token', '');
            if ($resp->successful() && $token !== '') {
                Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addHours(23));

                return $token;
            }
        } catch (Throwable $e) {
            Log::warning('hazdar: TDX token failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** @return array<mixed>|null */
    public function get(string $path): ?array
    {
        $c = config('hazdar.sources.transit');
        $token = $this->token();
        if ($token === null) {
            return null;
        }

        $url = rtrim($c['base_url'], '/').$path;
        $resp = Http::withToken($token)->timeout(15)->get($url, ['$format' => 'JSON']);
        if ($resp->status() === 401) {
            $token = $this->token(fresh: true); // 重取一次
            if ($token === null) {
                return null;
            }
            $resp = Http::withToken($token)->timeout(15)->get($url, ['$format' => 'JSON']);
        }

        return $resp->successful() ? (array) $resp->json() : null;
    }
}
```

- [ ] **Step 3: Failing test** (`tests/Feature/Commands/FetchTransitAlertsTest.php`)

```php
<?php

namespace Tests\Feature\Commands;

use App\Models\TransitAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchTransitAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hazdar.sources.transit.tdx_client_id' => 'cid',
            'hazdar.sources.transit.tdx_client_secret' => 'sec']);
        Cache::forget('hazdar:tdx:token');
    }

    private function fakeTdx(array $metro, array $gondola, array $bus): void
    {
        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'T', 'expires_in' => 86400]),
            '*Rail/Metro/Alert/TRTC*' => Http::response(['Alerts' => $metro]),   // 注意 TRTC 前綴也吃 TRTCMG，故下方 gondola pattern 要更 specific
            '*Rail/Metro/Alert/TRTCMG*' => Http::response(['Alerts' => $gondola]),
            '*Bus/Alert/City/Taipei*' => Http::response(['Alerts' => $bus]),
        ]);
    }

    public function test_normal_sentinel_stored_as_normal(): void
    {
        $this->fakeTdx(
            metro: [['AlertID' => '0', 'Title' => '正常營運', 'Status' => 1, 'Scope' => ['Lines' => []]]],
            gondola: [['AlertID' => '0', 'Title' => '目前全線正常營運', 'Status' => 1, 'Scope' => ['Lines' => []]]],
            bus: [['AlertID' => '20556', 'Title' => '寒假調整', 'Status' => 2, 'Cause' => 11, 'Effect' => 1,
                'Scope' => ['Routes' => [['RouteName' => ['Zh_tw' => '通勤28']]]]]],
        );

        $this->artisan('hazdar:fetch-transit-alerts')->assertSuccessful();

        // metro/gondola 正常 sentinel → status 1
        $this->assertSame(1, TransitAlert::where('system', 'metro')->sole()->status);
        $this->assertSame(1, TransitAlert::where('system', 'gondola')->sole()->status);
        // 公車例行 alert（Cause=11 活動、非氣候/停駛）→ 被過濾掉，不落地
        $this->assertSame(0, TransitAlert::where('system', 'bus')->count());
    }

    public function test_weather_bus_suspension_is_kept(): void
    {
        $this->fakeTdx(
            metro: [['AlertID' => '5', 'Title' => '文湖線停駛', 'Status' => 0, 'Cause' => 6,
                'Scope' => ['Lines' => [['LineName' => ['Zh_tw' => '文湖線']]]]]],
            gondola: [['AlertID' => '0', 'Title' => '正常', 'Status' => 1, 'Scope' => []]],
            bus: [['AlertID' => '99', 'Title' => '颱風停駛', 'Status' => 0, 'Cause' => 6, 'Effect' => 4,
                'Scope' => ['Routes' => [['RouteName' => ['Zh_tw' => '299']]]]]],
        );

        $this->artisan('hazdar:fetch-transit-alerts')->assertSuccessful();

        $this->assertSame(0, TransitAlert::where('system', 'metro')->sole()->status);
        $this->assertSame('文湖線', TransitAlert::where('system', 'metro')->sole()->scope_name);
        $bus = TransitAlert::where('system', 'bus')->sole();
        $this->assertSame(0, $bus->status);
        $this->assertSame('299', $bus->scope_name);
    }

    public function test_skips_cleanly_without_credentials(): void
    {
        config(['hazdar.sources.transit.tdx_client_id' => null,
            'hazdar.sources.transit.tdx_client_secret' => null]);

        $this->artisan('hazdar:fetch-transit-alerts')->assertSuccessful();
        $this->assertSame(0, TransitAlert::count());
    }
}
```

- [ ] **Step 4: Run to fail**, then **write command** (`app/Console/Commands/FetchTransitAlerts.php`)

```php
<?php

namespace App\Console\Commands;

use App\Models\TransitAlert;
use App\Services\DataSourceHealth;
use App\Services\TdxClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TDX 交通警示（spec §11.5 P2 Phase 2，每 5 分鐘，credential-gated）。
 * 捷運/纜車正常時回單一 sentinel AlertID "0" / Status 1；公車只收災害相關（Cause=6 或 Effect∈{4,7}）。
 */
class FetchTransitAlerts extends Command
{
    private const WEATHER_CAUSE = 6;

    private const SUSPEND_EFFECTS = [4, 7];

    protected $signature = 'hazdar:fetch-transit-alerts';

    protected $description = '拉取 TDX 捷運/纜車/公車警示並落地 transit_alerts';

    public function handle(DataSourceHealth $health, TdxClient $tdx): int
    {
        if (! $tdx->configured()) {
            $this->info('TDX 憑證未設定，略過交通警示（不影響其他源）');

            return self::SUCCESS;
        }

        $config = config('hazdar.sources.transit');

        try {
            $rows = [];
            foreach (['metro' => TransitAlert::SYSTEM_METRO, 'gondola' => TransitAlert::SYSTEM_GONDOLA, 'bus' => TransitAlert::SYSTEM_BUS] as $key => $system) {
                $data = $tdx->get($config['endpoints'][$key]);
                if ($data === null) {
                    throw new \RuntimeException("TDX {$key} 抓取失敗");
                }
                foreach ($this->mapAlerts($system, data_get($data, 'Alerts', [])) as $row) {
                    $rows[] = $row;
                }
            }

            // 全量快照：清掉舊的再重灌（本源獨立 table）
            TransitAlert::query()->delete();
            foreach ($rows as $row) {
                TransitAlert::create($row);
            }

            $health->recordSuccess('transit');
            $this->info('Transit alerts: '.count($rows).' rows');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('hazdar: transit fetch failed', ['error' => $e->getMessage()]);
            $health->recordFailure('transit', $e->getMessage());
            $this->error("Transit fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $alerts
     * @return list<array<string,mixed>>
     */
    private function mapAlerts(string $system, array $alerts): array
    {
        $out = [];
        foreach ($alerts as $a) {
            $status = (int) ($a['Status'] ?? 1);
            $cause = isset($a['Cause']) ? (int) $a['Cause'] : null;
            $effect = isset($a['Effect']) ? (int) $a['Effect'] : null;

            // 捷運/纜車：正常 sentinel（AlertID "0" 或 Status 1）→ 存一筆 status=1 代表全線正常
            if ($system !== TransitAlert::SYSTEM_BUS) {
                $isNormal = ((string) ($a['AlertID'] ?? '')) === '0' || $status === TransitAlert::STATUS_NORMAL;
                $out[] = $this->row($system, $a, $isNormal ? 1 : $status, $cause, $effect, $this->railScope($a));
                if ($isNormal) {
                    // 正常時每系統只留一筆代表
                    return [$out[count($out) - 1]];
                }

                continue;
            }

            // 公車：只收災害相關（氣候 或 停駛/重大延遲），其餘例行 alert 丟棄
            if ($cause === self::WEATHER_CAUSE || in_array($effect, self::SUSPEND_EFFECTS, true)) {
                $out[] = $this->row($system, $a, $status, $cause, $effect, $this->busScope($a));
            }
        }

        return $out;
    }

    private function railScope(array $a): ?string
    {
        return data_get($a, 'Scope.Lines.0.LineName.Zh_tw') ?? data_get($a, 'Scope.Lines.0.LineName');
    }

    private function busScope(array $a): ?string
    {
        return data_get($a, 'Scope.Routes.0.RouteName.Zh_tw') ?? data_get($a, 'Scope.Routes.0.RouteName');
    }

    private function row(string $system, array $a, int $status, ?int $cause, ?int $effect, ?string $scope): array
    {
        return [
            'system' => $system,
            'identifier' => (string) ($a['AlertID'] ?? ''),
            'scope_name' => $scope,
            'status' => $status,
            'cause' => $cause,
            'effect' => $effect,
            'title' => mb_substr((string) ($a['Title'] ?? ''), 0, 255) ?: null,
            'description' => (string) ($a['Description'] ?? '') ?: null,
            'effective_at' => $this->parseTime($a['StartTime'] ?? null),
            'expires_at' => $this->parseTime($a['EndTime'] ?? null),
        ];
    }

    private function parseTime(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
```

**注意** test 的 Http::fake pattern 順序：`*Rail/Metro/Alert/TRTC*` 會同時吃到 `TRTCMG`。Laravel `Http::fake` 依序匹配、第一個命中者勝，故 **TRTCMG 的 pattern 要排在 TRTC 之前**，或改用更精確 pattern（`*Alert/TRTC?*` 難精確）。實作時把 gondola pattern 放前面：先 `'*Alert/TRTCMG*'` 再 `'*Alert/TRTC*'`。更新 `fakeTdx()` 的陣列順序讓 TRTCMG 在 TRTC 前。

- [ ] **Step 5: Schedule** — `routes/console.php`: `Schedule::command('hazdar:fetch-transit-alerts')->everyFiveMinutes()->runInBackground();`

- [ ] **Step 6: Run + commit** — `composer dump-autoload -o`, test PASS, pint.
```bash
git add app/Services/TdxClient.php app/Console/Commands/FetchTransitAlerts.php config/hazdar.php routes/console.php tests/Feature/Commands/FetchTransitAlertsTest.php
git commit -m "feat: TDX 交通警示抓取（捷運/纜車/公車，credential-gated，正常 sentinel + 公車過濾）"
```

---

## Task 10: /info 交通段 + 首頁卡捷運摘要

**Files:**
- Modify: `app/Support/CityInfo.php`（`transitBySystem()` 回真實資料）
- Modify: `resources/views/partials/city-info/transit.blade.php`
- Modify: `resources/views/livewire/partials/card-info.blade.php`（加捷運整體狀態）
- Test: `tests/Feature/CityInfoPageTest.php`（加交通斷言）

- [ ] **Step 1: CityInfo::transitBySystem** — 回落地資料分組：

```php
public static function transitBySystem(): array
{
    return \App\Models\TransitAlert::orderBy('system')->get()
        ->groupBy('system')
        ->map(fn ($rows) => $rows->map(fn ($a) => [
            'scope' => $a->scope_name,
            'status' => $a->status,      // 0 停駛 / 1 正常 / 2 調整
            'title' => $a->title,
        ])->all())
        ->all();
}

public static function metroOverallStatus(): ?int
{
    $rows = \App\Models\TransitAlert::where('system', 'metro')->pluck('status');
    if ($rows->isEmpty()) {
        return null;
    }

    return $rows->min(); // 有 0 就 0（停駛）、有 2 就 2、全 1 才 1
}
```

- [ ] **Step 2: transit.blade.php** — 渲染各系統狀態徽章（badge 映射：1→正常 emerald、2→調整 amber、0→停駛 rose）：

```blade
@php
    $badge = fn (int $s) => match ($s) {
        1 => ['正常', 'bg-emerald-500/20 text-emerald-300'],
        2 => ['調整', 'bg-amber-500/20 text-amber-300'],
        default => ['停駛', 'bg-rose-500/20 text-rose-300'],
    };
    $sysLabel = ['metro' => '捷運', 'gondola' => '貓空纜車', 'bus' => '公車'];
@endphp
@if (! empty($transit))
    <section class="mt-6 rounded-xl border border-white/10 bg-white/5 p-4">
        <h2 class="mb-3 text-lg font-semibold text-slate-200">🚇 大眾交通</h2>
        @foreach ($transit as $system => $rows)
            @foreach ($rows as $r)
                @php [$txt, $cls] = $badge($r['status']); @endphp
                <div class="flex items-center justify-between border-b border-white/5 py-1.5 text-sm last:border-0">
                    <span class="text-slate-300">{{ $sysLabel[$system] ?? $system }}{{ $r['scope'] ? '・'.$r['scope'] : '' }}</span>
                    <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $cls }}">{{ $txt }}</span>
                </div>
            @endforeach
        @endforeach
    </section>
@endif
```

- [ ] **Step 3: 首頁卡捷運摘要** — `eoc-dashboard.blade.php` sharedCardData 的 `cityInfoSummary` 加 `'metroStatus' => \App\Support\CityInfo::metroOverallStatus()`；`card-info.blade.php` 加一行捷運整體（null→不顯示或「—」）。

- [ ] **Step 4: 測試** — `CityInfoPageTest` 加：

```php
public function test_page_shows_transit_status(): void
{
    \App\Models\TransitAlert::create(['system' => 'metro', 'scope_name' => '文湖線', 'status' => 0, 'title' => '停駛']);
    $this->get('/info')->assertOk()->assertSee('捷運')->assertSee('停駛');
}
```

- [ ] **Step 5: Run + build + commit** — test PASS, `npm run build`, `view:clear`, pint。全套測試綠。
```bash
git add app/Support/CityInfo.php resources/views/partials/city-info/transit.blade.php resources/views/livewire/partials/card-info.blade.php resources/views/livewire/eoc-dashboard.blade.php tests/Feature/CityInfoPageTest.php
git commit -m "feat: /info 交通段 + 首頁卡捷運摘要（Phase 2 完成）"
```

---

## 收尾（全 Task 完成後）

- 全套 `php artisan test --compact` 綠。
- 觸發各 command 實跑一次落地生產資料：`php artisan hazdar:fetch-pump-status`、`hazdar:sync-floodgates`、`hazdar:fetch-ncdr-alerts`、`hazdar:fetch-transit-alerts`。
- 線上實機驗證 `/info` 桌機+手機、首頁卡、nav。
- 更新 `docs/hazdar-spec.md`（§11.5 標記 P2 已實現、附本輪）與 `.superpowers/sdd/progress.md`。
</content>
