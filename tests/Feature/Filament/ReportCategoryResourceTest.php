<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ReportCategories\Pages\CreateReportCategory;
use App\Filament\Resources\ReportCategories\Pages\ListReportCategories;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
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

    public function test_extra_field_numeric_values_are_stored_as_int_even_when_submitted_as_strings(): void
    {
        $this->actingAs($this->admin());

        // 瀏覽器往返會把 min/max/presets.*.value 變成字串；後端必須強制轉型（避免嚴格比對失效）
        Livewire::test(CreateReportCategory::class)
            ->fillForm([
                'slug' => 'landslide', 'name' => '土石流', 'emoji' => '⛰️',
                'color' => '#22c55e', 'sort_order' => 70, 'is_active' => true,
                'extra_field' => [
                    'label' => '掩埋深度', 'unit' => 'cm', 'min' => '0', 'max' => '300',
                    'presets' => [['value' => '25', 'hint' => '測試']],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $extra = ReportCategory::bySlug('landslide')->extra_field;
        $this->assertSame(0, $extra['min']);
        $this->assertSame(300, $extra['max']);
        $this->assertSame(25, $extra['presets'][0]['value']);
    }

    public function test_invalid_color_is_rejected(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateReportCategory::class)
            ->fillForm([
                'slug' => 'landslide', 'name' => '土石流', 'emoji' => '⛰️',
                'color' => 'sky blue', 'sort_order' => 70, 'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['color']);
    }

    public function test_bulk_delete_skips_categories_with_reports_and_reports_partial_failure(): void
    {
        $this->actingAs($this->admin());
        Report::factory()->create(['category' => 'flood']);
        $flood = ReportCategory::where('slug', 'flood')->first();
        $empty = ReportCategory::where('slug', 'water_outage')->first();

        Livewire::test(ListReportCategories::class)
            ->selectTableRecords([$flood, $empty])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
            // 受保護分類被略過時，管理員必須收到「部分刪除」而非「全數刪除」的通知
            ->assertNotified(trans_choice(
                'filament-actions::delete.multiple.notifications.deleted_partial.title',
                1,
                ['count' => 1, 'total' => 2],
            ));

        $this->assertNotNull(ReportCategory::bySlug('flood'));
        $this->assertNull(ReportCategory::bySlug('water_outage'));
    }
}
