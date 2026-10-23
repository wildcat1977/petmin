<?php

namespace Tests\Feature\Services;

use App\Services\WalkingDistanceService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 步行路網是選配：公益 OSRM 對同一個 IP 有流量上限（實測連打 3 次就被封鎖），
 * 正式機所有使用者都從同一台伺服器發出請求。網址留空＝關掉路網，直接用直線估算，不能每次白等逾時。
 */
class WalkingDistanceServiceTest extends TestCase
{
    public function test_blank_osrm_url_skips_the_network_entirely(): void
    {
        config(['petmin.find_bin.walking.osrm_url' => '']);
        Http::fake();

        $this->assertNull(app(WalkingDistanceService::class)->distancesFrom(25.0466, 121.5245, [['lat' => 25.0385, 'lng' => 121.5172]]));
        Http::assertNothingSent();
    }

    /** 公益服務的使用規範要求識別來源：帶上站名與網址的 User-Agent */
    public function test_requests_identify_the_application(): void
    {
        config(['petmin.find_bin.walking.osrm_url' => 'https://osrm.test/routed-foot']);
        Http::fake(['osrm.test/*' => Http::response(['code' => 'Ok', 'distances' => [[0, 1234.5]]])]);

        $this->assertSame([1234.5], app(WalkingDistanceService::class)->distancesFrom(25.0466, 121.5245, [['lat' => 25.0385, 'lng' => 121.5172]]));
        Http::assertSent(fn (Request $r) => str_contains($r->header('User-Agent')[0] ?? '', 'Petmin'));
    }
}
