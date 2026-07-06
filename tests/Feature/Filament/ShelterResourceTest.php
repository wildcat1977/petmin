<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Shelters\Pages\EditShelter;
use App\Filament\Resources\Shelters\Pages\ListShelters;
use App\Models\Shelter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class ShelterResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function makeShelter(array $attributes = []): Shelter
    {
        return Shelter::create(array_merge([
            'name' => '中正區收容所',
            'latitude' => 25.0330000,
            'longitude' => 121.5650000,
            'address' => '台北市中正區忠孝西路一段',
            'capacity' => 120,
            'is_open' => false,
            'source' => 'data.taipei',
        ], $attributes));
    }

    public function test_list_page_renders_shelter_rows(): void
    {
        $open = $this->makeShelter(['name' => '開設中收容所', 'is_open' => true, 'external_id' => 'ext-1']);
        $closed = $this->makeShelter(['name' => '未開設收容所', 'external_id' => 'ext-2']);

        Livewire::actingAs($this->admin)
            ->test(ListShelters::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$open, $closed]);
    }

    public function test_toggling_is_open_updates_database_and_forgets_map_cache(): void
    {
        $shelter = $this->makeShelter(['is_open' => false]);

        Cache::put('map.shelters', ['stale-payload']);

        Livewire::actingAs($this->admin)
            ->test(ListShelters::class)
            ->call('updateTableColumnState', 'is_open', (string) $shelter->getKey(), true);

        $this->assertTrue($shelter->refresh()->is_open);
        // 切換開設狀態必須讓公開地圖立即反映，因此快取須被清除
        $this->assertNull(Cache::get('map.shelters'));
    }

    public function test_saving_edit_page_forgets_map_cache(): void
    {
        $shelter = $this->makeShelter(['is_open' => false]);

        Cache::put('map.shelters', ['stale-payload']);

        Livewire::actingAs($this->admin)
            ->test(EditShelter::class, ['record' => $shelter->getRouteKey()])
            ->fillForm(['is_open' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($shelter->refresh()->is_open);
        $this->assertNull(Cache::get('map.shelters'));
    }
}
