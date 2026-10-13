<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TrashBins\Pages\ListTrashBins;
use App\Models\TrashBin;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TrashBinResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_bins_and_mark_one_missing_and_back(): void
    {
        $admin = User::factory()->admin()->create();
        $bin = TrashBin::factory()->create(['address' => '公園路口']);

        Livewire::actingAs($admin)
            ->test(ListTrashBins::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$bin])
            ->callAction(TestAction::make('mark_missing')->table($bin));

        $this->assertSame(TrashBin::STATUS_MISSING, $bin->fresh()->status);

        Livewire::actingAs($admin)
            ->test(ListTrashBins::class)
            ->callAction(TestAction::make('mark_active')->table($bin));

        $this->assertSame(TrashBin::STATUS_ACTIVE, $bin->fresh()->status);
    }
}
