<?php

namespace Tests\Feature;

use App\Models\DemandProxy;
use App\Models\Report;
use App\Models\TrashBin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 提案產出頁：候選復設點（含證據、CSV）、有袋沒桶、皮克敏榜。 */
class CandidatesAndLeaderboardPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidates_page_lists_cells_with_evidence_and_gaps(): void
    {
        DemandProxy::factory()->create(['kind' => DemandProxy::KIND_DOG_PARK, 'name' => '華山狗活動區', 'latitude' => 25.0470, 'longitude' => 121.5265]);
        TrashBin::factory()->dogBagBox()->create(['address' => '孤單袋箱', 'latitude' => 25.1000, 'longitude' => 121.5000]);
        $this->artisan('demand:recompute')->assertSuccessful();

        $this->withHeader('Accept-Language', 'zh-TW')->get('/candidates')
            ->assertOk()
            ->assertSee('華山狗活動區')
            ->assertSee('孤單袋箱')
            ->assertSee('有袋沒桶');
    }

    public function test_candidates_csv_export_contains_ranked_cells(): void
    {
        DemandProxy::factory()->create(['kind' => DemandProxy::KIND_DOG_PARK, 'name' => '華山狗活動區', 'latitude' => 25.0470, 'longitude' => 121.5265]);
        $this->artisan('demand:recompute');

        $res = $this->get('/candidates/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('華山狗活動區', $res->streamedContent());
    }

    public function test_demand_and_gaps_map_endpoints(): void
    {
        DemandProxy::factory()->create(['kind' => DemandProxy::KIND_DOG_PARK, 'latitude' => 25.0470, 'longitude' => 121.5265]);
        TrashBin::factory()->dogBagBox()->create(['latitude' => 25.1000, 'longitude' => 121.5000]);
        $this->artisan('demand:recompute');

        $this->getJson('/api/map/demand')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/map/gaps')->assertOk()->assertJsonCount(1);
    }

    public function test_leaderboard_ranks_by_points_and_respects_privacy(): void
    {
        $top = User::factory()->create(['name' => '遛狗王']);
        $private = User::factory()->create(['name' => '低調人', 'points' => 20, 'is_private' => true]);
        User::factory()->create(['name' => '零點', 'points' => 0]);
        // 核准回報會透過 observer 加點並重算 streak，所以 streak 在回報建立後再設定
        Report::factory()->approved()->create(['user_id' => $top->id]);
        $top->forceFill(['points' => 50, 'streak_days' => 3])->save();

        $html = $this->withHeader('Accept-Language', 'zh-TW')->get('/leaderboard')->assertOk()->getContent();

        $this->assertStringContainsString('遛狗王', $html);
        $this->assertStringContainsString('匿名皮克敏', $html);
        $this->assertStringNotContainsString('低調人', $html);
        $this->assertStringNotContainsString('零點', $html);
        // 前三名頒獎台是 2nd/1st/3rd 排版，改比完整榜單（最後一次出現）的順序
        $this->assertTrue(strrpos($html, '遛狗王') < strrpos($html, '匿名皮克敏'));
        $this->assertStringContainsString('🔥 3', $html);
    }
}
