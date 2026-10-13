<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 回報表單與桶的關聯：吸附桶會寫入 trash_bin_id；「這裡需要桶」不掛桶；從桶 popup 進來預設位置與分類。 */
class ReportBinSnapTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapped_bin_is_saved_with_report(): void
    {
        $user = User::factory()->create();
        $bin = TrashBin::factory()->create(['address' => '公園路口']);

        Livewire::actingAs($user)->test('report-form')
            ->call('show')
            ->set('category', Report::CATEGORY_FULL)
            ->call('setSnappedBin', $bin->id, '公園路口', TrashBin::KIND_CLEAN_BOX, 12)
            ->assertSee('吸附到：🗑️ 公園路口')
            ->set('latitude', 25.0330)
            ->set('longitude', 121.5654)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame($bin->id, Report::sole()->trash_bin_id);
    }

    public function test_need_bin_report_ignores_snapped_bin(): void
    {
        $user = User::factory()->create();
        $bin = TrashBin::factory()->create();

        Livewire::actingAs($user)->test('report-form')
            ->call('show')
            ->call('setSnappedBin', $bin->id, 'x', TrashBin::KIND_CLEAN_BOX, 5)
            ->set('category', Report::CATEGORY_NEED_BIN)
            ->set('latitude', 25.0330)
            ->set('longitude', 121.5654)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertNull(Report::sole()->trash_bin_id);
    }

    public function test_opening_from_bin_popup_presets_location_bin_and_full_category(): void
    {
        $user = User::factory()->create();
        $bin = TrashBin::factory()->create(['district' => '大安區', 'latitude' => 25.0300, 'longitude' => 121.5400]);

        Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form', binId: $bin->id)
            ->assertSet('open', true)
            ->assertSet('trashBinId', $bin->id)
            ->assertSet('category', Report::CATEGORY_FULL)
            ->assertSet('presetLocation', true)
            ->assertSet('latitude', 25.03)
            ->assertSeeHtml('data-preset-lat="25.03"');
    }

    public function test_clear_snapped_bin_removes_association(): void
    {
        $user = User::factory()->create();
        $bin = TrashBin::factory()->create();

        Livewire::actingAs($user)->test('report-form')
            ->dispatch('open-report-form', binId: $bin->id)
            ->call('clearSnappedBin')
            ->assertSet('trashBinId', null)
            ->assertDontSee('吸附到');
    }
}
