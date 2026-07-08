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
        $this->fakeTdx(
            metro: [['AlertID' => '0', 'Title' => '正常營運', 'Status' => 1, 'Scope' => ['Lines' => []]]],
            gondola: [['AlertID' => '0', 'Title' => '目前全線正常營運', 'Status' => 1, 'Scope' => ['Lines' => []]]],
            bus: [['AlertID' => '20556', 'Title' => '寒假調整', 'Status' => 2, 'Cause' => 11, 'Effect' => 1,
                'Scope' => ['Routes' => [['RouteName' => ['Zh_tw' => '通勤28']]]]]],
        );

        $this->artisan('hazdar:fetch-transit-alerts')->assertSuccessful();

        // metro/gondola 正常 sentinel → status 1
        $this->assertSame(1, TransitAlert::where('system', 'metro')->sole()->status);
        $this->assertSame(1, TransitAlert::where('system', 'gondola')->sole()->status);
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

    public function test_skips_cleanly_without_credentials(): void
    {
        config(['hazdar.sources.transit.tdx_client_id' => null,
            'hazdar.sources.transit.tdx_client_secret' => null]);

        $this->artisan('hazdar:fetch-transit-alerts')->assertSuccessful();
        $this->assertSame(0, TransitAlert::count());
    }
}
