<?php

namespace Tests\Feature\Commands;

use App\Models\ExternalAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchClosureAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function closureFeed(string $areaDesc = '臺北市'): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:cap="urn:oasis:names:tc:emergency:cap:1.1">
  <entry>
    <id>CLOSURE_TPE_1</id>
    <title>停班停課</title>
    <author><name>行政院人事行政總處</name></author>
    <link rel="alternate" href="https://alerts.test/cap/closure.cap" />
    <summary type="html">臺北市明天停止上班、停止上課。</summary>
    <cap:effective>2026/7/8 下午 06:00:00</cap:effective>
    <cap:expires>2026/7/9 下午 06:00:00</cap:expires>
  </entry>
</feed>
XML;
    }

    private function capXml(string $areaDesc): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
  <info><severity>Severe</severity><area><areaDesc>{$areaDesc}</areaDesc></area></info>
</alert>
XML;
    }

    public function test_closure_alerts_land_with_closure_type_and_taipei_flag(): void
    {
        Http::fake([
            'alerts.ncdr.nat.gov.tw/RssAtomFeed.ashx?AlertType=33' => Http::response($this->closureFeed()),
            'alerts.ncdr.nat.gov.tw/*' => Http::response('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>'),
            'alerts.test/cap/closure.cap' => Http::response($this->capXml('臺北市')),
        ]);

        $this->artisan('hazdar:fetch-ncdr-alerts')->assertSuccessful();

        $alert = ExternalAlert::where('alert_type', '停班停課')->sole();
        $this->assertTrue($alert->covers_taipei);
        $this->assertStringContainsString('停止上班', $alert->headline);
    }
}
