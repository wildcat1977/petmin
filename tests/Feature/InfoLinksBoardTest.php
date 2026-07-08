<?php

namespace Tests\Feature;

use App\Models\PumpStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 首頁「交通與清運資訊彙整」卡（spec §11.5）：Task 6 起改為 parsed 摘要
 * （停班停課狀態 / 抽水站運轉中 N / 收容所開設 N）+ 連往 /info 的連結，
 * 完整逐區塊連結清單已移至 App\Support\CityInfo 驅動的 /info 頁（見 CityInfoPageTest）。
 */
class InfoLinksBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_card_shows_parsed_summary_and_links_to_city_info(): void
    {
        PumpStation::factory()->count(2)->create(['pumb_status' => '運轉中']);

        $this->withHeader('Accept-Language', 'zh-TW')->get('/')
            ->assertOk()
            ->assertSee('抽水站運轉中')
            ->assertSee(route('city-info'), false);
    }
}
