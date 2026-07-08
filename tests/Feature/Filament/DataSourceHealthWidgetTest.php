<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\DataSourceHealthWidget;
use App\Models\Report;
use App\Models\User;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 後台健康 widget（spec §1-5）：scheduled fetch 失敗必須反映在後台狀態燈，
 * 承接原公開 dashboard footer 的角色（§11.1 將公開燈號移入後台）。
 */
class DataSourceHealthWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_shows_source_status_and_today_counts(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $health = app(DataSourceHealth::class);
        $health->recordSuccess('cwa_observations');
        $health->recordFailure('radar_echo', 'timeout');

        Report::factory()->create(['verification_level' => Report::VERIFICATION_OFFICIAL]);
        Report::factory()->create();

        Livewire::test(DataSourceHealthWidget::class)
            ->assertSee('CWA')                 // 某個資料源標籤
            ->assertSee('今日回報')
            ->assertSee('官方核實');
    }

    public function test_down_source_is_visibly_red(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        app(DataSourceHealth::class)->recordFailure('radar_echo', 'boom');

        // 從未成功 → STATUS_DOWN；widget 應標記為紅（data-status 屬性）
        Livewire::test(DataSourceHealthWidget::class)
            ->assertSee(DataSourceHealth::STATUS_DOWN);
    }

    /**
     * 承接自舊 eoc-dashboard footer 的斷言（已移除，spec §11.1）：四個外部源
     * 全都要有狀態燈，禁止默默跳過任一個。
     *
     * 注意：config('hazdar.sources') 之後新增了 pump/floodgates 等來源（其健康狀態改在
     * /info 頁呈現），故此處改以 DataSourceHealth::SOURCE_LABELS（widget 白名單）為準，
     * 而非整份 config，避免此測試隨 config 新增來源而膨脹。
     */
    public function test_all_four_external_sources_have_status_lights(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        app(DataSourceHealth::class)->recordSuccess('ncdr_alerts');

        $html = Livewire::test(DataSourceHealthWidget::class)->html();

        foreach (array_keys(DataSourceHealth::SOURCE_LABELS) as $source) {
            $this->assertStringContainsString('title="'.$source, $html);
        }
        $this->assertSame(4, substr_count($html, 'data-status='));
    }
}
