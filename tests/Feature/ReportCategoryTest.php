<?php

namespace Tests\Feature;

use App\Models\ReportCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 分類後台化（spec §11.7）：DB 為唯一事實來源、60s 快取寫入即 flush、
 * migration 內建 7 類 seed、停用不影響既有資料讀取。
 */
class ReportCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_seven_categories_in_order(): void
    {
        $slugs = ReportCategory::activeOrdered()->pluck('slug')->all();

        $this->assertSame(
            ['need_bin', 'full', 'missing', 'exists', 'bag_empty', 'dumping', 'hazard'],
            $slugs
        );
        $this->assertSame('桶滿了', ReportCategory::labels()['full']);
        $this->assertSame('#38bdf8', ReportCategory::bySlug('bag_empty')->color);
    }

    public function test_full_extra_field_config(): void
    {
        $extra = ReportCategory::bySlug('full')->extra_field;

        $this->assertSame('滿溢程度', $extra['label']);
        $this->assertSame('%', $extra['unit']);
        $this->assertSame(0, $extra['min']);
        $this->assertSame(100, $extra['max']);
        $this->assertSame(50, $extra['presets'][0]['value']);
        $this->assertNull(ReportCategory::bySlug('missing')->extra_field);
    }

    public function test_deactivation_removes_from_active_but_keeps_in_labels(): void
    {
        ReportCategory::where('slug', 'need_bin')->first()->update(['is_active' => false]);

        $this->assertNotContains('need_bin', ReportCategory::activeSlugs());
        $this->assertContains('need_bin', ReportCategory::slugs());
        $this->assertArrayHasKey('need_bin', ReportCategory::labels());
        $this->assertSame('full', ReportCategory::defaultSlug());
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
