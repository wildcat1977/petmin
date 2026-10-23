<?php

namespace Tests\Feature\Api;

use App\Models\FarSignal;
use App\Models\TrashBin;
use App\Models\User;
use App\Support\DemandGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 「好遠！」匿名訊號：不用登入、只存格中心、同 IP 同格同天只算一次、登入時記下使用者。 */
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

    public function test_anonymous_user_can_send_far_signal_stored_at_cell_center(): void
    {
        $bin = TrashBin::factory()->create(['district' => '中正區']);

        $this->postJson('/api/far-signals', $this->payload(['nearest_bin_id' => $bin->id]))
            ->assertCreated()
            ->assertJsonPath('accepted', true);

        $signal = FarSignal::sole();
        $grid = DemandGrid::fromConfig();
        [$ix, $iy] = $grid->cellAt(25.0466, 121.5245);
        $center = $grid->center($ix, $iy);

        $this->assertNull($signal->user_id);
        $this->assertSame($grid->id($ix, $iy), $signal->cell);
        $this->assertEqualsWithDelta($center['lat'], (float) $signal->latitude, 0.000001);
        $this->assertNotEquals(25.0466, (float) $signal->latitude); // 不存精確位置
        $this->assertSame('中正區', $signal->district);
        $this->assertSame(64, strlen($signal->ip_hash));
    }

    public function test_same_ip_same_cell_same_day_counts_once_but_other_cell_counts(): void
    {
        $this->postJson('/api/far-signals', $this->payload())->assertCreated();
        $this->postJson('/api/far-signals', $this->payload(['lat' => 25.04661]))   // 同一格
            ->assertOk()
            ->assertJsonPath('accepted', false);
        $this->postJson('/api/far-signals', $this->payload(['lat' => 25.0530]))    // 約 700m 外，另一格
            ->assertCreated();

        $this->assertSame(2, FarSignal::count());
    }

    public function test_next_day_counts_again(): void
    {
        $this->postJson('/api/far-signals', $this->payload())->assertCreated();
        $this->travel(1)->days();
        $this->postJson('/api/far-signals', $this->payload())->assertCreated();

        $this->assertSame(2, FarSignal::count());
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
