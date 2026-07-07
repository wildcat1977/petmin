# Hazdar v0.2 Phase 2b — 回報分類後台化 + 地圖三態 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 回報分類改由 `report_categories` 表驅動（後台可增/停/改名/改色，spec §11.7，含新增停水、大型物品掉落 2 類），並為地圖點位加入 回報/已通報/已排除 三態（spec §11.8）。

**Architecture:** 新 `ReportCategory` model 帶 60 秒快取的靜態查詢 helper，成為分類的唯一事實來源；`reports.category` 維持 varchar slug（不改 FK，佐證/索引不動）。`water_depth_cm` 更名為通用 `extra_value`，各分類的選填數值欄由 `extra_field` jsonb 設定驅動。三態以 `notified_at`（新增）+ `resolved_at`（既有）推導，地圖 API 預設回傳已排除點位（帶 `st` 欄位），前端以樣式區分。全 codebase 5 份硬編碼分類視覺表（app.js/report-form/eoc-dashboard/report-list/stats+transparency）全部改讀 DB。

**Tech Stack:** Laravel 13 / PHP 8.4 / Livewire 4 SFC / Filament 5 / Leaflet / PHPUnit 12 / PostgreSQL（tests 跑 sqlite，migration 需相容兩者）。

## Global Constraints

- 測試一律 PHPUnit class（不用 Pest），跑法 `php artisan test --compact tests/...`；改 PHP 後 commit 前必跑 `vendor/bin/pint --dirty --format agent`。
- Commit message 用 `feat:`/`fix:`/`test:` 前綴，結尾 `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`。
- `reports.category` 仍是 slug 字串；`app/Jobs/EvaluateCorroboration.php` 與三個含 category 的索引**不得修改**。
- slug 建立後唯讀；**已有回報之分類禁止刪除，只能停用**；停用只關閉新回報入口，既有點位、統計、篩選照常顯示（災情資料不得憑空消失）。
- 分類變更傳播延遲 ≤ 60s（快取 TTL 60 + 寫入時 flush）。
- 6 個 seed 分類（slug/名稱/emoji/色碼）——**一字不改**：
  - `flood` 淹水 💧 `#38bdf8`，extra_field `{"label":"積水深度","unit":"cm","min":0,"max":500,"presets":[{"value":10,"hint":"腳踝"},{"value":30,"hint":"小腿"},{"value":50,"hint":"及膝"},{"value":100,"hint":"及腰"},{"value":150,"hint":"一層樓"}]}`
  - `road_blocked` 道路中斷 🚧 `#fb923c`
  - `power_outage` 停電 ⚡ `#facc15`
  - `structural_damage` 結構受損 🏚️ `#f87171`
  - `water_outage` 停水 🚱 `#a78bfa`（新）
  - `falling_object` 大型物品掉落 🌳 `#d97706`（新，含樹木）
- UI 文字 `__('中文')` key；`lang/en.json` 補：`"停水": "Water Outage"`、`"大型物品掉落": "Falling Objects"`。`lang/zh_TW.json` 不動。
- 三態推導優先序固定：`resolved_at` → `resolved`（已排除）＞ `notified_at` → `notified`（已通報）＞ 無 → 回報。
- 點位 API 新欄位鍵名：`extra`（取代 `depth`）、`st`（handling state，回報態時省略）。
- 免責彈窗、雷達回波（Phase 2a 產物）不得動。

---

### Task 1: `report_categories` 表 + ReportCategory model（快取 helper + seed 6 類）

**Files:**
- Create: `database/migrations/2026_07_07_000001_create_report_categories_table.php`
- Create: `app/Models/ReportCategory.php`
- Create: `database/factories/ReportCategoryFactory.php`
- Modify: `database/seeders/DemoDisasterSeeder.php:38-43`（demo 權重加 2 新類）
- Test: `tests/Feature/ReportCategoryTest.php`

**Interfaces:**
- Consumes: 無
- Produces（後續全部 task 依賴，簽章固定）:
  - `ReportCategory::activeOrdered(): \Illuminate\Support\Collection`（active、依 sort_order，60s 快取）
  - `ReportCategory::allOrdered(): \Illuminate\Support\Collection`（含停用，60s 快取）
  - `ReportCategory::labels(): array`（全部 slug => name，60s 快取）
  - `ReportCategory::slugs(): array`（全部 slug）
  - `ReportCategory::activeSlugs(): array`（active slug）
  - `ReportCategory::defaultSlug(): string`（第一個 active，無資料時回 `'flood'`）
  - `ReportCategory::find via slug`: `ReportCategory::bySlug(string $slug): ?self`
  - model 欄位：`slug,name,emoji,color,sort_order,is_active,extra_field(array cast)`
  - 寫入/刪除自動 `Cache::forget('hazdar:report-categories')`

- [ ] **Step 1: 寫失敗的測試**

```php
<?php

namespace Tests\Feature;

use App\Models\ReportCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 分類後台化（spec §11.7）：DB 為唯一事實來源、60s 快取寫入即 flush、
 * migration 內建 6 類 seed、停用不影響既有資料讀取。
 */
class ReportCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_six_categories_in_order(): void
    {
        $slugs = ReportCategory::activeOrdered()->pluck('slug')->all();

        $this->assertSame(
            ['flood', 'road_blocked', 'power_outage', 'structural_damage', 'water_outage', 'falling_object'],
            $slugs
        );
        $this->assertSame('淹水', ReportCategory::labels()['flood']);
        $this->assertSame('#a78bfa', ReportCategory::bySlug('water_outage')->color);
    }

    public function test_flood_extra_field_config(): void
    {
        $extra = ReportCategory::bySlug('flood')->extra_field;

        $this->assertSame('積水深度', $extra['label']);
        $this->assertSame('cm', $extra['unit']);
        $this->assertSame(0, $extra['min']);
        $this->assertSame(500, $extra['max']);
        $this->assertSame(10, $extra['presets'][0]['value']);
        $this->assertNull(ReportCategory::bySlug('power_outage')->extra_field);
    }

    public function test_deactivation_removes_from_active_but_keeps_in_labels(): void
    {
        ReportCategory::where('slug', 'flood')->first()->update(['is_active' => false]);

        $this->assertNotContains('flood', ReportCategory::activeSlugs());
        $this->assertContains('flood', ReportCategory::slugs());
        $this->assertArrayHasKey('flood', ReportCategory::labels());
        $this->assertSame('road_blocked', ReportCategory::defaultSlug());
    }

    public function test_cache_is_flushed_on_save(): void
    {
        ReportCategory::labels(); // 灌快取

        ReportCategory::create([
            'slug' => 'landslide', 'name' => '土石流', 'emoji' => '⛰️',
            'color' => '#22c55e', 'sort_order' => 99, 'is_active' => true,
        ]);

        $this->assertArrayHasKey('landslide', ReportCategory::labels());
    }
}
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact tests/Feature/ReportCategoryTest.php`
Expected: FAIL — `Class "App\Models\ReportCategory" not found`

- [ ] **Step 3: 實作**

Migration `2026_07_07_000001_create_report_categories_table.php`：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 回報分類後台化（spec §11.7）：分類移入 DB，reports.category 維持 slug 字串鍵。
 * 基礎 6 類直接在 migration 內 seed（部署即生效，不依賴 db:seed）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();   // 建立後唯讀，程式與 reports.category 以此為鍵
            $table->string('name', 32);
            $table->string('emoji', 8)->nullable();
            $table->char('color', 7);               // hex，如 #38bdf8
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('extra_field')->nullable(); // {label, unit, min, max, presets?: [{value, hint}]}
            $table->timestamps();
        });

        $now = now();
        $floodExtra = json_encode([
            'label' => '積水深度', 'unit' => 'cm', 'min' => 0, 'max' => 500,
            'presets' => [
                ['value' => 10, 'hint' => '腳踝'], ['value' => 30, 'hint' => '小腿'],
                ['value' => 50, 'hint' => '及膝'], ['value' => 100, 'hint' => '及腰'],
                ['value' => 150, 'hint' => '一層樓'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        DB::table('report_categories')->insert([
            ['slug' => 'flood', 'name' => '淹水', 'emoji' => '💧', 'color' => '#38bdf8', 'sort_order' => 10, 'is_active' => true, 'extra_field' => $floodExtra, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'road_blocked', 'name' => '道路中斷', 'emoji' => '🚧', 'color' => '#fb923c', 'sort_order' => 20, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'power_outage', 'name' => '停電', 'emoji' => '⚡', 'color' => '#facc15', 'sort_order' => 30, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'structural_damage', 'name' => '結構受損', 'emoji' => '🏚️', 'color' => '#f87171', 'sort_order' => 40, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'water_outage', 'name' => '停水', 'emoji' => '🚱', 'color' => '#a78bfa', 'sort_order' => 50, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'falling_object', 'name' => '大型物品掉落', 'emoji' => '🌳', 'color' => '#d97706', 'sort_order' => 60, 'is_active' => true, 'extra_field' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_categories');
    }
};
```

`app/Models/ReportCategory.php`：

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 回報分類（spec §11.7）：後台可增/停/改名/改色的唯一事實來源。
 * 讀取走 60s 快取（前台每個請求都要用），寫入即 flush；
 * slug 為程式鍵（reports.category 儲存值），建立後不可改。
 */
class ReportCategory extends Model
{
    use HasFactory;

    private const CACHE_KEY = 'hazdar:report-categories';

    protected $fillable = ['slug', 'name', 'emoji', 'color', 'sort_order', 'is_active', 'extra_field'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'extra_field' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** 全部分類（含停用），依 sort_order；60s 快取 */
    public static function allOrdered(): Collection
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => static::query()->orderBy('sort_order')->get());
    }

    public static function activeOrdered(): Collection
    {
        return self::allOrdered()->where('is_active', true)->values();
    }

    /** @return array<string, string> slug => name（含停用，舊點位仍需標籤） */
    public static function labels(): array
    {
        return self::allOrdered()->pluck('name', 'slug')->all();
    }

    /** @return list<string> 全部 slug（讀取端白名單：停用分類的既有點位仍可篩選） */
    public static function slugs(): array
    {
        return self::allOrdered()->pluck('slug')->all();
    }

    /** @return list<string> active slug（寫入端白名單：停用即關閉新回報） */
    public static function activeSlugs(): array
    {
        return self::activeOrdered()->pluck('slug')->all();
    }

    public static function defaultSlug(): string
    {
        return self::activeSlugs()[0] ?? 'flood';
    }

    public static function bySlug(string $slug): ?self
    {
        return self::allOrdered()->firstWhere('slug', $slug);
    }
}
```

`database/factories/ReportCategoryFactory.php`：

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ReportCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(1),
            'name' => $this->faker->word(),
            'emoji' => '📍',
            'color' => $this->faker->hexColor(),
            'sort_order' => $this->faker->numberBetween(70, 200),
            'is_active' => true,
            'extra_field' => null,
        ];
    }
}
```

`database/seeders/DemoDisasterSeeder.php` 的權重陣列（38-43 行附近）尾端加兩行：

```php
            'water_outage' => 15,
            'falling_object' => 25,
```

（該陣列原以 `Report::CATEGORY_*` 常數為鍵；新兩類直接用字串 slug，不新增常數。）

- [ ] **Step 4: 跑測試確認通過**

Run: `php artisan test --compact tests/Feature/ReportCategoryTest.php`
Expected: PASS（4 tests）

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_07_000001_create_report_categories_table.php app/Models/ReportCategory.php database/factories/ReportCategoryFactory.php database/seeders/DemoDisasterSeeder.php tests/Feature/ReportCategoryTest.php
git commit -m "feat: report_categories 表 + ReportCategory model（含 6 類 seed 與 60s 快取，spec §11.7）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: reports 表 schema — `water_depth_cm`→`extra_value` 更名 + `notified_at`（純機械改名，行為不變）

**Files:**
- Create: `database/migrations/2026_07_07_000002_generalize_reports_extra_value_and_add_notified_at.php`
- Modify: `app/Models/Report.php`（fillable 97、casts 117）
- Modify: `resources/views/livewire/report-form.blade.php`（PHP 區 `$waterDepthCm` → `$extraValue`；rules 42；updatedCategory 79-84；submit 136；show() reset 68；template 內 `waterDepthCm` wire:model 與 `water_depth_cm` 相關行）
- Modify: `app/Http/Controllers/Api/MapPointsController.php:84,98`（select 與 payload：`water_depth_cm`→`extra_value`，payload 鍵 `depth`→`extra`）
- Modify: `resources/js/app.js:186`（popup：`p.depth`→`p.extra`，本 task 僅改鍵名，flood 判斷 Task 5 再一般化）
- Modify: `routes/web.php:69,75`（CSV：header `水深(cm)`→`補充數值`、欄位 `water_depth_cm`→`extra_value`）
- Modify: `app/Filament/Resources/Reports/Schemas/ReportForm.php:33-37`（`water_depth_cm`→`extra_value`，label `補充數值`；visible 條件本 task 維持 flood 硬編碼，Task 4 改 DB 驅動）
- Modify: `database/factories/ReportFactory.php:23-24`（鍵名改 `extra_value`）
- Modify: 既有測試中所有 `water_depth_cm`/`waterDepthCm` 引用（`tests/Feature/ReportSubmissionTest.php`、`tests/Feature/Api/MapPointsApiTest.php` 等——先 grep 全部再逐一改）
- Test: 全套既有測試綠即為本 task 驗證（純改名不加新測試）

**Interfaces:**
- Consumes: 無（獨立於 Task 1）
- Produces: `reports.extra_value`（integer nullable）、`reports.notified_at`（timestamp nullable）；`Report::casts()` 含 `'extra_value' => 'integer'`、`'notified_at' => 'datetime'`；fillable 含兩者；Livewire 屬性 `$extraValue`；點位 API payload 鍵 `extra`

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * spec §11.7/§11.8：water_depth_cm 一般化為 extra_value（各分類選填數值欄），
 * 加 notified_at（已通報，Filament 手動標記）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('water_depth_cm', 'extra_value');
            $table->timestamp('notified_at')->nullable()->after('verification_level');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE reports ALTER COLUMN extra_value TYPE INTEGER');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE reports ALTER COLUMN extra_value TYPE SMALLINT');
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('extra_value', 'water_depth_cm');
            $table->dropColumn('notified_at');
        });
    }
};
```

- [ ] **Step 2: 全域機械改名**

先盤點：`grep -rn 'water_depth_cm\|waterDepthCm' app/ resources/ routes/ database/ tests/ --include='*.php' --include='*.js'`，每一處依下表改：

| 舊 | 新 |
|---|---|
| `water_depth_cm`（欄位/鍵名） | `extra_value` |
| `$waterDepthCm` / `'waterDepthCm'`（Livewire 屬性） | `$extraValue` / `'extraValue'` |
| API payload 鍵 `'depth' => $r->water_depth_cm` | `'extra' => $r->extra_value` |
| app.js `p.depth` | `p.extra` |
| CSV header `'水深(cm)'` | `'補充數值'` |
| Filament label `'淹水深度 (cm)'` | `'補充數值'` |

`Report::casts()` 改：`'water_depth_cm' => 'integer'` → `'extra_value' => 'integer'`，並加 `'notified_at' => 'datetime'`；`$fillable` 同步改 `'extra_value'` 並加 `'notified_at'`。

report-form PHP 區行為維持：`updatedCategory()` 與 `submit()` 的 `CATEGORY_FLOOD` 判斷**本 task 不動**（Task 3 改 DB 驅動），只改屬性名。template 的 `wire:model="waterDepthCm"` 與快選鈕 `$set('waterDepthCm', N)` 改為 `extraValue`。

- [ ] **Step 3: 跑全套測試**

Run: `php artisan test --compact`
Expected: 全綠（114+4 tests）。任何殘留 `water_depth_cm` 引用都會在此暴露。

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: water_depth_cm 一般化為 extra_value、reports 加 notified_at（spec §11.7/§11.8）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: 後端讀寫路徑改 DB 驅動（表單驗證/API 白名單/標籤查找）

**Files:**
- Modify: `app/Models/Report.php` — 刪 `CATEGORIES`（47-52）與 `CATEGORY_LABELS`（54-59）常數；保留 4 個 `CATEGORY_*` slug 常數；加 `category()` relation 與 `categoryLabel()`
- Modify: `resources/views/livewire/report-form.blade.php` PHP 區 — mount/show 預設分類、rules、updatedCategory、submit 改 DB 驅動
- Modify: `app/Http/Controllers/Api/MapPointsController.php:38-43` — 白名單改 `ReportCategory::slugs()`
- Modify: `routes/api.php:48` — v1 驗證改 `Rule::in(ReportCategory::slugs())`；行 22 的 doc comment 改為「slug 清單見 /api/map 分類設定，後台可增減」
- Modify: `routes/web.php:74` — CSV 標籤 `Report::CATEGORY_LABELS[...]` → `$report->categoryLabel()`
- Modify: `resources/views/livewire/radar-map.blade.php:38,51` — ticker 標籤改 `ReportCategory::labels()`
- Modify: `resources/views/livewire/eoc-dashboard.blade.php` — 刪 `CATEGORY_CARDS`（15-20），`getStatsProperty()`（84-99）改讀 `ReportCategory::activeOrdered()`（回傳含 `color` hex，template Task 5 改）
- Modify: `app/Services/ImageModerationService.php:161` — `$label = ReportCategory::labels()[$category] ?? $category;`
- Modify: `tests/Feature/ReportSubmissionTest.php`、`tests/Feature/EocDashboardTest.php`（適配新 stats 形狀）
- Test: `tests/Feature/ReportSubmissionTest.php` 追加動態分類測試

**Interfaces:**
- Consumes: Task 1 全部 helper；Task 2 的 `$extraValue`/`extra_value`
- Produces:
  - `Report::categoryLabel(): string`
  - `Report::category(): BelongsTo`（`belongsTo(ReportCategory::class, 'category', 'slug')`，方法名 `categoryModel()` 以免撞欄位——**注意：欄位名 category 與 relation 撞名，統一用 `categoryModel()`**）
  - eoc-dashboard `getStatsProperty()` 新形狀：`[{slug, label, color, count, delta}]`（list，不再 keyed by slug）

- [ ] **Step 1: 追加失敗的測試（ReportSubmissionTest 內）**

```php
    public function test_admin_created_category_is_immediately_reportable(): void
    {
        \App\Models\ReportCategory::create([
            'slug' => 'landslide', 'name' => '土石流', 'emoji' => '⛰️',
            'color' => '#22c55e', 'sort_order' => 99, 'is_active' => true,
        ]);

        $user = User::factory()->create();
        $this->submitReport($user, ['category' => 'landslide']);

        $this->assertDatabaseHas('reports', ['category' => 'landslide']);
    }

    public function test_deactivated_category_is_rejected_for_new_reports(): void
    {
        \App\Models\ReportCategory::where('slug', 'power_outage')->first()->update(['is_active' => false]);

        $user = User::factory()->create();

        $this->submitReport($user, ['category' => Report::CATEGORY_POWER_OUTAGE])
            ->assertHasErrors(['category']);
    }

    public function test_extra_value_outside_category_config_range_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->submitReport($user, ['category' => Report::CATEGORY_FLOOD, 'extraValue' => 501])
            ->assertHasErrors(['extraValue']);
    }

    public function test_extra_value_is_nulled_for_category_without_extra_field(): void
    {
        $user = User::factory()->create();

        $this->submitReport($user, ['category' => Report::CATEGORY_ROAD_BLOCKED, 'extraValue' => 50]);

        $this->assertNull(Report::latest('id')->first()->extra_value);
    }
```

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact tests/Feature/ReportSubmissionTest.php`
Expected: 新測試 FAIL（`in:` 名單仍是舊常數）

- [ ] **Step 3: 實作**

`Report.php`：刪掉 `CATEGORIES` 與 `CATEGORY_LABELS` 兩個常數區塊，加：

```php
    public function categoryModel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ReportCategory::class, 'category', 'slug');
    }

    public function categoryLabel(): string
    {
        return ReportCategory::labels()[$this->category] ?? $this->category;
    }
```

report-form PHP 區：

```php
    public string $category = '';

    public function mount(): void
    {
        $this->category = ReportCategory::defaultSlug();
    }
```

（`show()` 內 `$this->category = Report::CATEGORY_FLOOD;` 同改 `ReportCategory::defaultSlug()`。）

```php
    public function rules(): array
    {
        $extra = ReportCategory::bySlug($this->category)?->extra_field;

        return [
            'category'    => ['required', 'in:'.implode(',', ReportCategory::activeSlugs())],
            'extraValue'  => $extra
                ? ['nullable', 'integer', 'between:'.($extra['min'] ?? 0).','.($extra['max'] ?? 9999)]
                : ['nullable', 'integer'],
            'photo'       => ['nullable', 'image', 'max:20480'],
            'latitude'    => ['required', 'numeric', 'between:-90,90'],
            'longitude'   => ['required', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function updatedCategory(): void
    {
        if (! ReportCategory::bySlug($this->category)?->extra_field) {
            $this->extraValue = null;
        }
    }
```

submit() 內：

```php
            'category'    => $this->category,
            'extra_value' => ReportCategory::bySlug($this->category)?->extra_field ? $this->extraValue : null,
```

`MapPointsController` 38-42：`->intersect(Report::CATEGORIES)` → `->intersect(ReportCategory::slugs())`；空集合 fallback `collect(ReportCategory::slugs())`。

`routes/api.php` 48：`'category' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Models\ReportCategory::slugs())],`

ticker（radar-map.blade.php 38、51）：`$label = \App\Models\ReportCategory::labels()[$category] ?? $category;`（51 行同式）。

eoc-dashboard PHP：

```php
    public function getStatsProperty(): array
    {
        $rows = Report::query()
            ->visible()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('category, count(*) as day_count, count(case when created_at > ? then 1 end) as hour_count', [now()->subHour()])
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        return ReportCategory::activeOrdered()->map(fn ($cat) => [
            'slug' => $cat->slug,
            'label' => $cat->name,
            'color' => $cat->color,
            'count' => (int) ($rows[$cat->slug]->day_count ?? 0),
            'delta' => (int) ($rows[$cat->slug]->hour_count ?? 0),
        ])->all();
    }
```

Template 消費端（`@foreach ($this->stats as $stat)`，280-291）本 task 先最小適配：`$stat['class']`/`$stat['bar']` 改為 inline style `style="color: {{ $stat['color'] }}"` 與 `style="border-color: {{ $stat['color'] }}"`（完整視覺調整在 Task 5）。

`EocDashboardTest` 的 stats 斷言由 keyed-by-slug 改為 list 查找：`collect($stats)->firstWhere('slug', Report::CATEGORY_FLOOD)['count']`。

`ImageModerationService:161`：`$label = ReportCategory::labels()[$category] ?? $category;`（記得 `use App\Models\ReportCategory;`）。

- [ ] **Step 4: 跑相關測試**

Run: `php artisan test --compact tests/Feature/ReportSubmissionTest.php tests/Feature/EocDashboardTest.php tests/Feature/Api/MapPointsApiTest.php tests/Feature/Api/ReportsApiTest.php tests/Feature/CorroborationTest.php`
Expected: PASS

- [ ] **Step 5: 跑全套 + Pint + commit**

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: 分類驗證/白名單/標籤全面改讀 report_categories（spec §11.7）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Filament — ReportCategoryResource CRUD + Reports 後台改 DB 選項

**Files:**
- Create: `app/Filament/Resources/ReportCategories/ReportCategoryResource.php`
- Create: `app/Filament/Resources/ReportCategories/Schemas/ReportCategoryForm.php`
- Create: `app/Filament/Resources/ReportCategories/Tables/ReportCategoriesTable.php`
- Create: `app/Filament/Resources/ReportCategories/Pages/{ListReportCategories,CreateReportCategory,EditReportCategory}.php`
- Modify: `app/Filament/Resources/Reports/Tables/ReportsTable.php:66-75,139-141`
- Modify: `app/Filament/Resources/Reports/Schemas/ReportForm.php:27-37`
- Modify: `app/Filament/Resources/Users/RelationManagers/ReportsRelationManager.php:32-41,85-87`
- Test: `tests/Feature/Filament/ReportCategoryResourceTest.php`

**Interfaces:**
- Consumes: Task 1 model/helpers、Task 2 `extra_value`
- Produces: Filament 導覽「回報分類」CRUD。目錄/命名/三頁式結構完全比照 `app/Filament/Resources/DashboardResources/**`（先讀那三個檔案再動工）。

**規則（照 spec §11.7）：**
- `slug`：create 時必填 `alpha_dash` + unique；edit 時 `->disabled()`。
- 刪除：`DeleteAction`/`DeleteBulkAction` 以 `Report::where('category', $record->slug)->exists()` 檢查，有資料 → 不可刪（`->hidden()`），僅能停用（`ToggleColumn::make('is_active')`）。
- `extra_field` 用巢狀 statePath 編輯：`TextInput::make('extra_field.label')`、`extra_field.unit`、`extra_field.min`（numeric）、`extra_field.max`（numeric）、`Repeater::make('extra_field.presets')`（schema：`value` numeric + `hint` text），全部 nullable；存檔時若 `extra_field.label` 為空則整個 `extra_field` 存 null（用 `mutateFormDataUsing`）。
- Table：`reorderable('sort_order')`、`defaultSort('sort_order')`、欄位 emoji+name、slug（灰字）、色塊（`TextColumn` 用 `->badge()->color()` 或 ColorColumn）、is_active Toggle。

**Reports 後台改造：**
- `ReportsTable` badge（66-75）：`->formatStateUsing(fn (string $state) => ReportCategory::labels()[$state] ?? $state)`；顏色改 `->color(fn (string $state) => match ($state) { ... 原四類照舊 ..., default => 'info' })`（Filament palette 限制，新分類統一 info——hex 徽章列 v0.3）。
- Filter（139-141）：`->options(fn () => ReportCategory::labels())`。
- `ReportForm` Select（27-31）：`->options(fn () => ReportCategory::labels())`；`extra_value`（33-37）visible 改 `fn (Get $get) => (bool) (ReportCategory::bySlug($get('category') ?? '')?->extra_field)`。
- `ReportsRelationManager`（32-41、85-87）：同 ReportsTable 的 badge/filter 改法。

- [ ] **Step 1: 寫失敗的測試**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ReportCategories\Pages\CreateReportCategory;
use App\Filament\Resources\ReportCategories\Pages\ListReportCategories;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 分類後台管理（spec §11.7）：可新增含數值欄位設定的分類；
 * 已有回報之分類禁止刪除（只能停用）。
 */
class ReportCategoryResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // 依 tests/Feature/Filament/AdminPermissionTest.php 的既有作法取得可進後台的使用者
        // （先讀該檔，抄同一個 helper／factory state，不要自創權限機制）
        return User::factory()->admin()->create();
    }

    public function test_can_create_category_with_extra_field(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateReportCategory::class)
            ->fillForm([
                'slug' => 'landslide', 'name' => '土石流', 'emoji' => '⛰️',
                'color' => '#22c55e', 'sort_order' => 70, 'is_active' => true,
                'extra_field' => ['label' => '掩埋深度', 'unit' => 'cm', 'min' => 0, 'max' => 300, 'presets' => []],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('掩埋深度', ReportCategory::bySlug('landslide')->extra_field['label']);
    }

    public function test_category_with_reports_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());
        Report::factory()->create(['category' => 'flood']);
        $flood = ReportCategory::where('slug', 'flood')->first();

        Livewire::test(ListReportCategories::class)
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($flood));
    }

    public function test_category_without_reports_can_be_deleted(): void
    {
        $this->actingAs($this->admin());
        $empty = ReportCategory::where('slug', 'water_outage')->first();

        Livewire::test(ListReportCategories::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($empty));

        $this->assertNull(ReportCategory::bySlug('water_outage'));
    }
}
```

（`User::factory()->admin()` 為佔位假設——**動工前先讀 `tests/Feature/Filament/AdminPermissionTest.php` 與 `database/factories/UserFactory.php`，照既有方式建 admin**，測試與 helper 依實況調整。）

- [ ] **Step 2: 跑測試確認失敗**

Run: `php artisan test --compact tests/Feature/Filament/ReportCategoryResourceTest.php`
Expected: FAIL — resource 類不存在

- [ ] **Step 3: 實作（先讀 DashboardResources 三檔照抄結構；Filament 產生器 `php artisan make:filament-resource ReportCategory --no-interaction` 後照上述規則改）**

- [ ] **Step 4: 跑測試**

Run: `php artisan test --compact tests/Feature/Filament/`
Expected: PASS（含既有 Filament 測試）

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: Filament 回報分類 CRUD（slug 鎖定/停用保護/extra_field 設定），Reports 後台改 DB 選項

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: 前端 5 份硬編碼分類視覺表全面改 DB 驅動

**Files:**
- Modify: `resources/views/livewire/radar-map.blade.php`（PHP 區加 `getCategoryMetaProperty()`；容器 77 行附近加 `data-categories`）
- Modify: `resources/js/app.js:110-116,138,178-186,212,231`（`CATEGORY_META` 改 runtime 建構）
- Modify: `resources/views/pages/radar.blade.php:40-57`（分類 chips 改 `@foreach`）
- Modify: `resources/views/livewire/report-form.blade.php:226-260`（分類鈕 + extra_field 動態欄）
- Modify: `resources/views/livewire/eoc-dashboard.blade.php:266-272,280-291`（圖例 + 卡片）
- Modify: `resources/views/livewire/report-list.blade.php:13,41-42,92-99,233-239,263,326,423,480`（`$categoryConfig` 改 DB）
- Modify: `resources/views/pages/stats.blade.php:185-190`、`resources/views/pages/transparency.blade.php:285-291`（categoryRgb 改 @json）
- Modify: `resources/views/filament/components/map-picker.blade.php:118`、`resources/views/filament/modals/user-reports.blade.php:62`、`resources/views/livewire/user-profile.blade.php:160`、`resources/views/livewire/my-report-status.blade.php:51`（標籤查找改 `ReportCategory::labels()` / `$report->categoryLabel()`）
- Modify: `resources/views/layouts/app.blade.php:29-32`（移除 cat* i18n 鍵——標籤已由 blade 端翻譯後注入）
- Modify: `lang/en.json`（加 `"停水": "Water Outage"`、`"大型物品掉落": "Falling Objects"`；移除 4 個 emoji 前綴鍵 `"💧 淹水"` 等——按鈕改由 emoji 欄位+名稱組合）
- Test: `tests/Feature/DynamicCategoryUiTest.php`（新）

**Interfaces:**
- Consumes: Task 1 helpers、Task 3 的 stats 形狀
- Produces: `#radar-map` 容器新增 `data-categories`，JSON 形狀（app.js 依此建 `CATEGORY_META`）：
  `[{slug, name, emoji, color, active, extra: {label, unit} | null}]`（name 經 `__()` 翻譯）

**關鍵片段：**

radar-map PHP 區加：

```php
    public function getCategoryMetaProperty(): array
    {
        return \App\Models\ReportCategory::allOrdered()->map(fn ($cat) => [
            'slug' => $cat->slug,
            'name' => __($cat->name),
            'emoji' => $cat->emoji,
            'color' => $cat->color,
            'active' => $cat->is_active,
            'extra' => $cat->extra_field ? [
                'label' => __($cat->extra_field['label']),
                'unit' => $cat->extra_field['unit'] ?? '',
            ] : null,
        ])->values()->all();
    }
```

容器加 `data-categories='@json($this->categoryMeta)'`。

app.js 110-116 替換：

```js
// ── 災情分類視覺：由後台 report_categories 驅動（spec §11.7）──
const CATEGORY_META = {};
{
    const el = document.getElementById('radar-map');
    (JSON.parse(el?.dataset.categories || '[]')).forEach((c) => {
        CATEGORY_META[c.slug] = { color: c.color, label: () => c.name, active: c.active, extra: c.extra };
    });
}
```

行 138 `activeCategories` 改：`new Set(Object.entries(CATEGORY_META).filter(([, m]) => m.active).map(([slug]) => slug))`。
行 186 popup 改：

```js
    if (p.extra != null && meta?.extra) text += `（${meta.extra.label} ~${p.extra}${meta.extra.unit}）`;
```

radar.blade.php chips 40-48 改（49-56 的 shelters/radar_echo/flood_potential 三顆**保持原樣**）：

```blade
            @foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
                <button type="button" data-map-chip="{{ $cat->slug }}" style="--chip-color:{{ $cat->color }}"
                        class="map-chip chip-on">{{ $cat->emoji }} {{ __($cat->name) }}</button>
            @endforeach
```

report-form 分類鈕 226-238 改：

```blade
                                @foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
                                    <button type="button"
                                            wire:click="$set('category', '{{ $cat->slug }}')"
                                            @class([
                                                'flex items-center justify-center gap-2 rounded-xl border px-3 py-3 text-sm font-semibold transition',
                                                'border-white/10 bg-black/30 text-slate-400 hover:border-white/20' => $category !== $cat->slug,
                                            ])
                                            @if ($category === $cat->slug)
                                                style="border-color: {{ $cat->color }}99; background: {{ $cat->color }}26; color: {{ $cat->color }}; box-shadow: 0 0 18px {{ $cat->color }}59"
                                            @endif>
                                        {{ $cat->emoji }} {{ __($cat->name) }}
                                    </button>
                                @endforeach
```

extra_field 區塊（取代 243-260 的 flood 硬編碼）：

```blade
                        @php $extraField = \App\Models\ReportCategory::bySlug($category)?->extra_field; @endphp
                        @if ($extraField)
                            <div>
                                <label class="block text-xs font-medium text-slate-400">
                                    {{ __($extraField['label']) }}（{{ $extraField['unit'] ?? '' }}，{{ __('選填') }}）
                                </label>
                                @if (! empty($extraField['presets']))
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($extraField['presets'] as $preset)
                                            <button type="button" wire:click="$set('extraValue', {{ $preset['value'] }})"
                                                    class="rounded-lg border px-2.5 py-1.5 text-xs {{ $extraValue === $preset['value'] ? 'border-cyan-400/60 bg-cyan-500/15 text-cyan-200' : 'border-white/10 bg-black/30 text-slate-400' }}">
                                                {{ $preset['value'] }}{{ $extraField['unit'] ?? '' }} {{ __($preset['hint']) }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                <input type="number" wire:model="extraValue"
                                       min="{{ $extraField['min'] ?? 0 }}" max="{{ $extraField['max'] ?? 9999 }}"
                                       class="mt-2 w-full rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm"
                                       placeholder="{{ __($extraField['label']) }}">
                                @error('extraValue') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
                            </div>
                        @endif
```

（動工時以現有 243-260 的實際 markup 為準微調 class；水深快選鈕原樣式照抄。）

eoc-dashboard 圖例 269 行改：

```blade
                @foreach (\App\Models\ReportCategory::activeOrdered() as $cat)
                    <p class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" style="background: {{ $cat->color }}"></span>{{ $cat->name }}</p>
                @endforeach
                <p class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" style="background: #4ade80"></span>收容所</p>
```

report-list `$categoryConfig`（92-97）改由 `ReportCategory::allOrdered()` 建構（label = name、class 改 inline color style——依現有模板消費方式最小調整）；`<select>`（233-239）loop 同源。

stats/transparency 的 `categoryRgb`（兩處相同）改：

```blade
    const categoryHex = @json(\App\Models\ReportCategory::allOrdered()->pluck('color', 'slug'));
    const hexToRgb = (h) => h ? `${parseInt(h.slice(1,3),16)},${parseInt(h.slice(3,5),16)},${parseInt(h.slice(5,7),16)}` : '148,163,184';
    const categoryRgb = Object.fromEntries(Object.entries(categoryHex).map(([k, v]) => [k, hexToRgb(v)]));
```

- [ ] **Step 1: 寫失敗的測試**

```php
<?php

namespace Tests\Feature;

use App\Models\ReportCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 前端分類 UI 全面 DB 驅動（spec §11.7 驗收 4/5）：
 * 新分類即時出現在 chips/表單/data-categories；停用即時消失於新回報入口。
 */
class DynamicCategoryUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_six_seeded_category_chips(): void
    {
        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/')->getContent();

        foreach (['flood', 'water_outage', 'falling_object'] as $slug) {
            $this->assertStringContainsString('data-map-chip="'.$slug.'"', $html);
        }
        $this->assertStringContainsString('停水', $html);
        $this->assertStringContainsString('大型物品掉落', $html);
        $this->assertStringContainsString('data-categories', $html);
    }

    public function test_new_category_appears_without_code_change(): void
    {
        ReportCategory::create([
            'slug' => 'landslide', 'name' => '土石流', 'emoji' => '⛰️',
            'color' => '#22c55e', 'sort_order' => 70, 'is_active' => true,
        ]);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertSee('data-map-chip="landslide"', false)
            ->assertSee('土石流');
    }

    public function test_deactivated_category_chip_disappears_but_meta_retains_it(): void
    {
        ReportCategory::where('slug', 'flood')->first()->update(['is_active' => false]);

        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/')->getContent();

        $this->assertStringNotContainsString('data-map-chip="flood"', $html);
        // data-categories 仍含 flood（既有點位要能上色/顯示標籤）
        $this->assertMatchesRegularExpression('/data-categories=\'[^\']*flood/', $html);
    }
}
```

- [ ] **Step 2: 跑測試確認失敗** → Expected: FAIL（chips 仍硬編碼）

- [ ] **Step 3: 依上述片段實作全部檔案**

- [ ] **Step 4: 測試 + build**

```bash
php artisan test --compact tests/Feature/DynamicCategoryUiTest.php tests/Feature/DisclaimerTest.php tests/Feature/ReportSubmissionTest.php
npm run build
php artisan test --compact
```
Expected: 全綠、build 成功。

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: 前端分類視覺（chips/表單/圖例/清單/統計/後台地圖）全面改 DB 驅動（spec §11.7）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: 地圖三態（回報/已通報/已排除）

**Files:**
- Modify: `app/Http/Controllers/Api/MapPointsController.php:28,44,61,84,92-102`
- Modify: `resources/js/app.js`（`buildPointsLayer` 209-226、`createPopupEl` 165-207、`fetchReports` 275-279、chips 初始化）
- Modify: `resources/views/pages/radar.blade.php`（chips 區加「已排除」開關 chip）
- Modify: `app/Filament/Resources/Reports/Tables/ReportsTable.php:153-194`（加 mark_notified action）
- Modify: `app/Filament/Resources/Reports/Schemas/ReportForm.php`（加 notified_at DateTimePicker）
- Modify: `routes/web.php` CSV（69、72-79：加「已通報」欄）
- Modify: `resources/views/layouts/app.blade.php` window.i18n（加 stNotified/stResolved 兩鍵）+ `lang/en.json`
- Test: `tests/Feature/Api/MapPointsApiTest.php`（改既有 + 新增）

**Interfaces:**
- Consumes: Task 2 的 `notified_at`；Task 5 的 `CATEGORY_META` 結構
- Produces: 點位 API 每點新增 `'st' => 'notified'|'resolved'`（回報態省略）；query param `show_resolved` 預設值改 **true**；Filament action `mark_notified`

**行為規格（spec §11.8）：**
- 推導：`resolved_at` → `resolved` ＞ `notified_at` → `notified` ＞ 否則省略。
- **§7.1 行為變更**：resolved 點位從「預設隱藏」改「預設顯示第三態」，前端 chips 有「✅ 已排除」開關（預設開，關閉時 `fetchReports` 帶 `show_resolved=0`）。
- 視覺（circleMarker CSS 組合，不出圖檔）：`resolved` → fillColor `#64748b` 灰、fillOpacity 0.35、popup 標籤「已排除」；`notified` → 保留分類色、外框 `#22d3ee` cyan、weight 3、popup 標籤「已通報」。`verification_level` 的透明度分層保留（resolved 態優先覆蓋）。
- 跑馬燈與統計维持 `unresolved()`（已排除不進 24h 統計）——**不變**。
- 聚合 cluster 不分三態（維持 dominant color）。

- [ ] **Step 1: 改/加測試（MapPointsApiTest）**

既有 `test_pending_is_visible_but_rejected_and_resolved_are_hidden` 改名並反轉 resolved 語義：

```php
    public function test_resolved_is_visible_by_default_with_state_field(): void
    {
        Report::factory()->create(['category' => 'flood', 'latitude' => 25.03, 'longitude' => 121.56, 'resolved_at' => now()]);
        Report::factory()->create(['category' => 'flood', 'latitude' => 25.03, 'longitude' => 121.56, 'notified_at' => now()]);
        Report::factory()->create(['category' => 'flood', 'latitude' => 25.03, 'longitude' => 121.56]);

        $points = $this->getJson($this->url())->assertOk()->json('points');

        $states = collect($points)->pluck('st');
        $this->assertContains('resolved', $states);
        $this->assertContains('notified', $states);
        $this->assertCount(3, $points);
    }

    public function test_show_resolved_zero_hides_resolved(): void
    {
        Report::factory()->create(['category' => 'flood', 'latitude' => 25.03, 'longitude' => 121.56, 'resolved_at' => now()]);
        Report::factory()->create(['category' => 'flood', 'latitude' => 25.03, 'longitude' => 121.56]);

        $points = $this->getJson($this->url(['show_resolved' => 0]))->assertOk()->json('points');

        $this->assertCount(1, $points);
        $this->assertNull($points[0]['st'] ?? null);
    }

    public function test_resolved_beats_notified_in_state_derivation(): void
    {
        Report::factory()->create([
            'category' => 'flood', 'latitude' => 25.03, 'longitude' => 121.56,
            'notified_at' => now()->subHour(), 'resolved_at' => now(),
        ]);

        $points = $this->getJson($this->url())->assertOk()->json('points');
        $this->assertSame('resolved', $points[0]['st']);
    }
```

（`$this->url()` 為該測試檔既有的 bbox URL helper——先讀檔照既有寫法；rejected 仍隱藏的斷言保留在原測試或拆出。）

- [ ] **Step 2: 跑測試確認失敗** → Expected: 新測試 FAIL

- [ ] **Step 3: 實作**

MapPointsController：44 行 `$showResolved = (bool) ($validated['show_resolved'] ?? true);`；select（84）加 `'notified_at', 'resolved_at'`；payload map 加：

```php
                'st' => $r->resolved_at ? 'resolved' : ($r->notified_at ? 'notified' : null),
```

（`array_filter` 會自動略去 null——維持既有寫法。）**注意 cache key**（response cache 以 bbox+filter 為 key）：把 `show_resolved` 納入 cache key 組成，避免開關互相污染。

app.js `buildPointsLayer` 212-221 改：

```js
        const meta = CATEGORY_META[p.cat];
        const style = VERIFICATION_STYLE[p.vl] ?? VERIFICATION_STYLE.unverified;
        const resolved = p.st === 'resolved';
        const notified = p.st === 'notified';
        const baseColor = resolved ? '#64748b' : (meta?.color ?? '#94a3b8');
        const circle = L.circleMarker([p.lat, p.lng], {
            radius: 8,
            fillColor: baseColor,
            color: notified ? '#22d3ee' : (style.ring ? '#ffffff' : baseColor),
            weight: notified ? 3 : style.weight,
            opacity: 1,
            fillOpacity: resolved ? 0.35 : style.fillOpacity,
        });
```

`createPopupEl` 的 vl 標籤行（191）後補狀態徽章：

```js
    if (p.st) {
        const st = document.createElement('span');
        st.style.cssText = 'margin-left:6px;font-weight:600;font-size:11px;color:' + (p.st === 'resolved' ? '#94a3b8' : '#22d3ee');
        st.textContent = p.st === 'resolved' ? (window.i18n?.stResolved ?? '✅ 已排除') : (window.i18n?.stNotified ?? '📣 已通報');
        head.appendChild(st);
    }
```

`fetchReports` params（275-279）加：`if (! showResolved) params.set('show_resolved', '0');`，`let showResolved = true;` 模組層變數；chips 初始化（`initChips` 386-411）將 `data-map-chip="resolved"` 视為特殊開關（toggle `showResolved` + refetch），radar.blade.php chips 區在雷達回波 chip 前加：

```blade
            <button type="button" data-map-chip="resolved" style="--chip-color:#94a3b8"
                    class="map-chip chip-on">✅ {{ __('已排除') }}</button>
```

layouts/app.blade.php i18n 加 `stNotified: @js(__('📣 已通報'))`、`stResolved: @js(__('✅ 已排除'))`；en.json 加對照 `"📣 已通報": "📣 Notified"`、`"✅ 已排除": "✅ Resolved"`。

ReportsTable `mark_resolved` 前加：

```php
                Action::make('mark_notified')
                    ->label('標記已通報')
                    ->color('info')
                    ->icon('heroicon-o-megaphone')
                    ->visible(fn (Report $record) => $record->notified_at === null && $record->resolved_at === null)
                    ->action(fn (Report $record) => $record->update(['notified_at' => now()])),
```

ReportForm 的 resolved_at picker 旁加：

```php
                DateTimePicker::make('notified_at')
                    ->label('已通報時間')
                    ->nullable()
                    ->helperText('已通報市府/相關單位處理'),
```

CSV：header（69）在 `'已排除'` 前插 `'已通報'`；row（72-79）插 `$report->notified_at?->format('Y-m-d H:i') ?? '',`。

- [ ] **Step 4: 測試 + build**

```bash
php artisan test --compact tests/Feature/Api/MapPointsApiTest.php
npm run build
php artisan test --compact
```
Expected: 全綠。

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: 地圖三態（回報/已通報/已排除）+ 已排除預設顯示與開關（spec §11.8）

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Phase 2b 收尾驗證

- [ ] **Step 1: 全套測試** — `php artisan test --compact`，全綠；有紅修完才算完（Rule 12）。
- [ ] **Step 2: migrate 生產庫** — `php artisan migrate --force`（本機即生產環境）；確認 `report_categories` 6 筆、reports 欄位改名成功。
- [ ] **Step 3: build** — `npm run build`。
- [ ] **Step 4: 驗收 §11.9-4/5/7 手動檢查**（controller 以瀏覽器截圖執行）：首頁出現 6 顆分類 chips（含停水/掉落物）；後台停用一類 → 表單即消失（≤60s）；標記已通報/已排除 → 地圖 icon 變化。
- [ ] **Step 5: Checkpoint 記錄**：做了什麼/驗證了什麼/剩什麼。
