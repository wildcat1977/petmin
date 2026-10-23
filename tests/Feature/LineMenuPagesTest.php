<?php

namespace Tests\Feature;

use App\Models\TrashBin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** LINE 選單的三頁：我找垃圾桶（/find）、回報垃圾桶狀況（/report-bin）、怎麼使用（/guide）。 */
class LineMenuPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_bin_and_guide_pages_render(): void
    {
        $this->withoutVite();

        $this->get('/report-bin')->assertOk()->assertSee('回報垃圾桶狀況')->assertSee('找我身邊的垃圾桶');
        $this->get('/guide')->assertOk()->assertSee('小狗巡桶任務')
            ->assertSee('href="'.route('find').'"', false)
            ->assertSee('href="'.route('report-bin').'"', false);
    }

    public function test_find_api_returns_checkin_code_for_report_links(): void
    {
        $demo = config('petmin.find_bin.demo_point');
        TrashBin::factory()->create(['external_id' => 'clean_box-code1', 'latitude' => $demo['lat'] + 0.001, 'longitude' => $demo['lng']]);
        Http::fake(['*' => Http::response(['code' => 'Ok', 'distances' => [[0, 120]]])]);

        $this->getJson("/api/map/bins/find?lat={$demo['lat']}&lng={$demo['lng']}")
            ->assertOk()
            ->assertJsonPath('bins.0.code', 'clean_box-code1');
    }

    public function test_line_entry_also_plays_mission_intro(): void
    {
        $this->withoutVite();
        TrashBin::factory()->create(['external_id' => 'clean_box-line']);

        $this->get('/b/clean_box-line?via=line')->assertOk()->assertSee('今日巡桶任務開始');
    }
}
