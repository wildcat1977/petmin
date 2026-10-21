<?php

namespace Tests\Feature;

use App\Models\DogCard;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 扭蛋：回報送出後替最新那筆回報抽一張卡，同一筆只抽一次，未登入不抽。 */
class GachaPlayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_submitted_awards_one_card_and_dispatches_story(): void
    {
        $user = User::factory()->create();
        $report = Report::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)->test('gacha-player')
            ->dispatch('report-submitted')
            ->assertDispatched('gacha-play', fn (string $event, array $params) => in_array($params['story'], ['gold-silver', 'old-maid'], true)
                && $params['total'] === 2 && $params['collected'] === 1);

        $card = DogCard::sole();
        $this->assertSame($report->id, $card->report_id);
        $this->assertArrayHasKey($card->card_key, DogCard::definitions());
    }

    public function test_same_report_is_not_awarded_twice(): void
    {
        $user = User::factory()->create();
        Report::factory()->create(['user_id' => $user->id]);

        $component = Livewire::actingAs($user)->test('gacha-player');
        $component->dispatch('report-submitted');
        $component->dispatch('report-submitted')->assertNotDispatched('gacha-play');

        $this->assertSame(1, DogCard::count());
    }

    public function test_guest_gets_nothing(): void
    {
        Livewire::test('gacha-player')->dispatch('report-submitted')->assertNotDispatched('gacha-play');

        $this->assertSame(0, DogCard::count());
    }
}
