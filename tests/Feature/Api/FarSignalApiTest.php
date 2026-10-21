<?php

namespace Tests\Feature\Api;

use App\Models\FarSignal;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 「好遠！」匿名訊號：不用登入、同 IP 冷卻內不重複寫入、登入時記下使用者。 */
class FarSignalApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'lat' => 25.0466,
            'lng' => 121.5245,
            'nearest_distance_m' => 650,
            'distance_mode' => 'walking',
            ...$overrides,
        ];
    }

    public function test_anonymous_user_can_send_far_signal(): void
    {
        $bin = TrashBin::factory()->create(['district' => '中正區']);

        $this->postJson('/api/far-signals', $this->payload(['nearest_bin_id' => $bin->id]))
            ->assertCreated()
            ->assertJsonPath('accepted', true);

        $signal = FarSignal::sole();
        $this->assertNull($signal->user_id);
        $this->assertSame('中正區', $signal->district);
        $this->assertSame(650, $signal->nearest_distance_m);
        $this->assertSame(7, strlen($signal->geohash7));
        $this->assertSame(64, strlen($signal->ip_hash));
    }

    public function test_same_ip_within_cooldown_is_not_recorded_twice(): void
    {
        $this->postJson('/api/far-signals', $this->payload())->assertCreated();
        $this->postJson('/api/far-signals', $this->payload())
            ->assertOk()
            ->assertJsonPath('accepted', false);

        $this->assertSame(1, FarSignal::count());
    }

    public function test_logged_in_user_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/far-signals', $this->payload())->assertCreated();

        $this->assertSame($user->id, FarSignal::sole()->user_id);
    }

    public function test_rejects_unknown_distance_mode(): void
    {
        $this->postJson('/api/far-signals', $this->payload(['distance_mode' => 'teleport']))
            ->assertUnprocessable();
    }
}
