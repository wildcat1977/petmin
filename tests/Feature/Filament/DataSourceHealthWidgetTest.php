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
}
