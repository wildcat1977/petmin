<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BinNames\Pages\ListBinNames;
use App\Models\BinName;
use App\Models\TrashBin;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 桶名後台：AI 拿不準的由人工公開；已公開但不妥的撤下後，地圖退回上一個通過的名字。 */
class BinNameResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_approve_pending_and_revoke_live_name(): void
    {
        $admin = User::factory()->admin()->create();
        $bin = TrashBin::factory()->create();
        $owner = User::factory()->create();
        $older = BinName::create(['trash_bin_id' => $bin->id, 'user_id' => $owner->id, 'name' => '前一個名字', 'status' => BinName::STATUS_APPROVED]);
        $pending = BinName::create(['trash_bin_id' => $bin->id, 'user_id' => $owner->id, 'name' => '待審名字', 'status' => BinName::STATUS_PENDING]);

        Livewire::actingAs($admin)->test(ListBinNames::class)
            ->assertCanSeeTableRecords([$older, $pending])
            ->assertActionHidden(TestAction::make('approve')->table($older))
            ->callAction(TestAction::make('approve')->table($pending));
        $this->assertSame('待審名字', $bin->fresh()->currentName->name);

        Livewire::actingAs($admin)->test(ListBinNames::class)
            ->callAction(TestAction::make('revoke')->table($pending->fresh()));
        $this->assertSame('admin', $pending->fresh()->rejection_reason);
        $this->assertSame('前一個名字', $bin->fresh()->currentName->name);
    }

    public function test_non_admin_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/bin-names')->assertForbidden();
    }
}
