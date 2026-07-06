<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\DashboardResources\Pages\CreateDashboardResource;
use App\Filament\Resources\DashboardResources\Pages\EditDashboardResource;
use App\Filament\Resources\DashboardResources\Pages\ListDashboardResources;
use App\Models\DashboardResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardResourceResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function makeResource(array $attributes = []): DashboardResource
    {
        return DashboardResource::create(array_merge([
            'label' => '飲用水',
            'current_qty' => 50,
            'max_qty' => 100,
            'unit' => '箱',
            'sort_order' => 0,
        ], $attributes));
    }

    public function test_list_page_renders_resource_rows(): void
    {
        $water = $this->makeResource(['label' => '飲用水']);
        $blankets = $this->makeResource(['label' => '毛毯', 'unit' => '份', 'sort_order' => 1]);

        Livewire::actingAs($this->admin)
            ->test(ListDashboardResources::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$water, $blankets]);
    }

    public function test_create_persists_dashboard_resource(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateDashboardResource::class)
            ->fillForm([
                'label' => '睡袋',
                'current_qty' => 30,
                'max_qty' => 80,
                'unit' => '組',
                'sort_order' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('dashboard_resources', [
            'label' => '睡袋',
            'current_qty' => 30,
            'max_qty' => 80,
            'unit' => '組',
            'sort_order' => 2,
        ]);
    }

    public function test_edit_persists_updated_quantity(): void
    {
        $resource = $this->makeResource(['current_qty' => 50, 'max_qty' => 100]);

        Livewire::actingAs($this->admin)
            ->test(EditDashboardResource::class, ['record' => $resource->getRouteKey()])
            ->fillForm(['current_qty' => 75])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(75, $resource->refresh()->current_qty);
    }

    public function test_current_qty_exceeding_max_qty_is_rejected(): void
    {
        // 物資看板顯示「現量／上限」進度，現量超過上限代表資料錯誤，必須擋下
        Livewire::actingAs($this->admin)
            ->test(CreateDashboardResource::class)
            ->fillForm([
                'label' => '飲用水',
                'current_qty' => 120,
                'max_qty' => 100,
            ])
            ->call('create')
            ->assertHasFormErrors(['current_qty']);

        $this->assertDatabaseCount('dashboard_resources', 0);
    }
}
