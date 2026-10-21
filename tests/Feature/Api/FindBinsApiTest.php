<?php

namespace Tests\Feature\Api;

use App\Models\TrashBin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 我找垃圾桶：只找清潔箱、依步行距離排序、路網失敗退回直線距離。 */
class FindBinsApiTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 25.0466;

    private const LNG = 121.5245;

    public function test_returns_clean_boxes_sorted_by_walking_distance(): void
    {
        // 直線較近（約 110m）但要繞路；直線較遠（約 220m）步行反而近
        $nearButDetour = TrashBin::factory()->create(['latitude' => 25.0476, 'longitude' => self::LNG, 'address' => '河對岸']);
        $farButDirect = TrashBin::factory()->create(['latitude' => 25.0486, 'longitude' => self::LNG, 'address' => '直走就到']);
        Http::fake(['*/table/v1/foot/*' => Http::response(['code' => 'Ok', 'distances' => [[0, 900, 260]]])]);

        $res = $this->getJson('/api/map/bins/find?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('distance_mode', 'walking');

        $this->assertSame([$farButDirect->id, $nearButDetour->id], collect($res->json('bins'))->pluck('id')->all());
        $this->assertSame(260, $res->json('bins.0.distance_m'));
        $this->assertSame(4, $res->json('bins.0.walk_minutes'));
        $this->assertStringContainsString('travelmode=walking', $res->json('bins.0.nav_url'));
    }

    public function test_excludes_dog_bag_boxes_and_missing_bins(): void
    {
        $clean = TrashBin::factory()->create(['latitude' => 25.0470, 'longitude' => self::LNG]);
        TrashBin::factory()->dogBagBox()->create(['latitude' => 25.0467, 'longitude' => self::LNG]);
        TrashBin::factory()->missing()->create(['latitude' => 25.0467, 'longitude' => self::LNG]);
        Http::fake(['*' => Http::response(['code' => 'Ok', 'distances' => [[0, 50]]])]);

        $res = $this->getJson('/api/map/bins/find?lat='.self::LAT.'&lng='.self::LNG)->assertOk();

        $this->assertSame([$clean->id], collect($res->json('bins'))->pluck('id')->all());
    }

    public function test_falls_back_to_estimated_walking_distance_when_router_fails(): void
    {
        $near = TrashBin::factory()->create(['latitude' => 25.0470, 'longitude' => self::LNG]);
        $far = TrashBin::factory()->create(['latitude' => 25.0490, 'longitude' => self::LNG]);
        Http::fake(['*' => Http::response('boom', 500)]);

        $res = $this->getJson('/api/map/bins/find?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('distance_mode', 'estimated');

        $this->assertSame([$near->id, $far->id], collect($res->json('bins'))->pluck('id')->all());
        // 估算＝直線 × 1.3
        $this->assertEqualsWithDelta($res->json('bins.0.straight_m') * 1.3, $res->json('bins.0.distance_m'), 1);
    }

    public function test_reports_days_since_someone_confirmed_the_bin(): void
    {
        TrashBin::factory()->create(['latitude' => 25.0470, 'longitude' => self::LNG, 'last_verified_at' => now()->subDays(3)]);
        TrashBin::factory()->create(['latitude' => 25.0475, 'longitude' => self::LNG, 'last_verified_at' => null]);
        Http::fake(['*' => Http::response(['code' => 'Ok', 'distances' => [[0, 50, 100]]])]);

        $res = $this->getJson('/api/map/bins/find?lat='.self::LAT.'&lng='.self::LNG)->assertOk();

        $this->assertSame(3, $res->json('bins.0.confirmed_days_ago'));
        $this->assertNull($res->json('bins.1.confirmed_days_ago'));
    }

    public function test_expands_search_radius_and_returns_empty_when_nothing_nearby(): void
    {
        $distant = TrashBin::factory()->create(['latitude' => 25.0566, 'longitude' => self::LNG]); // 約 1.1km，要擴到第三圈
        Http::fake(['*' => Http::response(['code' => 'Ok', 'distances' => [[0, 1300]]])]);

        $this->getJson('/api/map/bins/find?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('bins.0.id', $distant->id);

        $this->getJson('/api/map/bins/find?lat=25.20&lng=121.40')
            ->assertOk()
            ->assertJsonCount(0, 'bins');
    }

    public function test_requires_coordinates(): void
    {
        $this->getJson('/api/map/bins/find')->assertUnprocessable();
    }

    public function test_find_page_renders(): void
    {
        $this->withoutVite();

        $this->get('/find')->assertOk()->assertSee('我找垃圾桶')->assertSee('好遠！');
    }
}
