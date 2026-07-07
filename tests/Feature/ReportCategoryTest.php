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
