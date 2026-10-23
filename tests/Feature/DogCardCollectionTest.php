<?php

namespace Tests\Feature;

use App\Models\DogCard;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 垃圾桶圖鑑：已收集的卡顯示名稱與張數，沒收集到的顯示 ？？？；只算自己的卡。 */
class DogCardCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_collected_cards_and_hides_missing_ones(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach (range(1, 2) as $i) {
            DogCard::create(['user_id' => $user->id, 'report_id' => Report::factory()->create(['user_id' => $user->id])->id, 'card_key' => 'honest-pooper']);
        }
        DogCard::create(['user_id' => $other->id, 'report_id' => Report::factory()->create(['user_id' => $other->id])->id, 'card_key' => 'super-clean-bin']);

        config(['petmin.gacha.dex_slots' => 16]);

        Livewire::actingAs($user)->test('dog-card-collection')
            ->assertViewHas('cards', fn ($cards) => $cards->count() === 16) // 2 張真卡＋14 格剪影
            ->assertSee('已收集 1 / 16')
            ->assertSee('誠實的丟屎人')
            ->assertSee('收集 2 張')
            ->assertDontSee('超級乾淨的垃圾桶')
            ->assertSee('？？？');
    }

    public function test_profile_page_includes_collection(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create())->get('/profile')->assertOk()->assertSee('垃圾桶圖鑑');
    }
}
