<?php

namespace Tests\Feature\Commands;

use App\Models\TransitAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchTransitAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hazdar.sources.transit.tdx_client_id' => 'cid',
            'hazdar.sources.transit.tdx_client_secret' => 'sec']);
        Cache::forget('hazdar:tdx:token');
    }

    private function fakeTdx(array $metro, array $gondola, array $bus): void
    {
        Http::fake([
            '*openid-connect/token' => Http::response(['access_token' => 'T', 'expires_in' => 86400]),
            '*Rail/Metro/Alert/TRTCMG*' => Http::response(['Alerts' => $gondola]),
            '*Rail/Metro/Alert/TRTC*' => Http::response(['Alerts' => $metro]),   // 注意 TRTC 前綴也吃 TRTCMG，故上方 gondola pattern 要更 specific 且排在前面
            '*Bus/Alert/City/Taipei*' => Http::response(['Alerts' => $bus]),
        ]);
    }

    public function test_normal_sentinel_stored_as_normal(): void
    {
        // gondola 給一個「與 metro 明顯不同」的真實中斷 fixture：若 TRTC/TRTCMG fake 排序錯誤
        // 導致 gondola 收到 metro 的 sentinel，下方 status/scope_name 斷言會一起失敗（讓 guard 真的能 fail）。
        $this->fakeTdx(
            metro: [['AlertID' => '0', 'Title' => '正常營運', 'Status' => 1, 'Scope' => ['Lines' => []]]],
            gondola: [['AlertID' => '7', 'Title' => '纜車維護停駛', 'Status' => 0,
                'Scope' => ['Lines' => [['LineName' => ['Zh_tw' => '貓空纜車線']]]]]],
            bus: [['AlertID' => '20556', 'Title' => '寒假調整', 'Status' => 2, 'Cause' => 11, 'Effect' => 1,
                'Scope' => ['Routes' => [['RouteName' => ['Zh_tw' => '通勤28']]]]]],
        );

        $this->artisan('hazdar:fetch-transit-alerts')->assertSuccessful();

        // metro 正常 sentinel → status 1
        $this->assertSame(1, TransitAlert::where('system', 'metro')->sole()->status);
        // gondola 真實中斷 → status 0 + 專屬 scope_name（證明 gondola 收到自己的 fixture，非 metro 的）
        $this->assertSame(0, TransitAlert::where('system', 'gondola')->sole()->status);
        $this->assertSame('貓空纜車線', TransitAlert::where('system', 'gondola')->sole()->scope_name);
        // 公車例行 alert（Cause=11 活動、非氣候/停駛）→ 被過濾掉，不落地
        $this->assertSame(0, TransitAlert::where('system', 'bus')->count());
    }

    public function test_weather_bus_suspension_is_kept(): void
    {
        $this->fakeTdx(
            metro: [['AlertID' => '5', 'Title' => '文湖線停駛', 'Status' => 0, 'Cause' => 6,
                'Scope' => ['Lines' => [['LineName' => ['Zh_tw' => '文湖線']]]]]],
            gondola: [['AlertID' => '0', 'Title' => '正常', 'Status' => 1, 'Scope' => []]],
            bus: [['AlertID' => '99', 'Title' => '颱風停駛', 'Status' => 0, 'Cause' => 6, 'Effect' => 4,
                'Scope' => ['Routes' => [['RouteName' => ['Zh_tw' => '299']]]]]],
        );

        $this->artisan('hazdar:fetch-transit-alerts')->assertSuccessful();

        $this->assertSame(0, TransitAlert::where('system', 'metro')->sole()->status);
        $this->assertSame('文湖線', TransitAlert::where('system', 'metro')->sole()->scope_name);
        $bus = TransitAlert::where('system', 'bus')->sole();
        $this->assertSame(0, $bus->status);
        $this->assertSame('299', $bus->scope_name);
    }

    public function test_rail_disruption_before_sentinel_is_not_collapsed_to_normal(): void
    {
        // 同一批 metro 資料裡先出現真實中斷、後面才跟著「全線正常」sentinel：
        // 絕不可讓 sentinel 蓋掉已收集到的異常（否則真實中斷會被吃掉，事故時漏報）。
        $this->fakeTdx(
            metro: [
                ['AlertID' => '5', 'Status' => 0, 'Scope' => ['Lines' => [['LineName' => ['Zh_tw' => '文湖線']]]]],
                ['AlertID' => '0', 'Status' => 1],
            ],
            gondola: [],
            bus: [],
        );

        $this->artisan('hazdar:fetch-transit-alerts')->assertSuccessful();

        $this->assertTrue(
            TransitAlert::where('system', 'metro')->where('status', 0)->where('scope_name', '文湖線')->exists()
        );
        $this->assertSame(0, TransitAlert::where('system', 'metro')->where('status', 1)->count());
    }

    public function test_skips_cleanly_without_credentials(): void
    {
        config(['hazdar.sources.transit.tdx_client_id' => null,
            'hazdar.sources.transit.tdx_client_secret' => null]);

        $this->artisan('hazdar:fetch-transit-alerts')->assertSuccessful();
        $this->assertSame(0, TransitAlert::count());
    }
}
