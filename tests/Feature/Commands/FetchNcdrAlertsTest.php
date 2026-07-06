<?php

namespace Tests\Feature\Commands;

use App\Models\ExternalAlert;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * NCDR CAP 拉取（spec §5 / 驗收 §1-2）：新警戒 5 分鐘輪詢落地，
 * 涵蓋台北市判定於 ingest 時完成，跑馬燈只讀落地資料。
 * 失敗必須反映在健康狀態——禁止默默跳過（驗收 §1-5）。
 */
class FetchNcdrAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function feedXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:cap="urn:oasis:names:tc:emergency:cap:1.1">
  <id>https://alerts.ncdr.nat.gov.tw/RSS.aspx</id>
  <title>NCDR_CAP-即時防災資訊</title>
  <entry>
    <id>CAP_TAIPEI_001</id>
    <title>土石流</title>
    <author><name>農業部</name></author>
    <link rel="alternate" href="https://alerts.test/cap/taipei.cap" />
    <summary type="html">大台北地區土石流黃色警戒，北投、文山、內湖請留意。</summary>
    <cap:effective>2026/7/6 下午 02:17:34</cap:effective>
    <cap:expires>2026/7/8 下午 05:00:00</cap:expires>
  </entry>
  <entry>
    <id>CAP_SOUTH_001</id>
    <title>停水</title>
    <author><name>台灣自來水公司</name></author>
    <link rel="alternate" href="https://alerts.test/cap/south.cap" />
    <summary type="html">高雄市楠梓區搶修停水。</summary>
    <cap:effective>2026/7/6 上午 09:00:00</cap:effective>
    <cap:expires>2026/7/7 上午 12:30:00</cap:expires>
  </entry>
  <entry>
    <id>CAP_TAIPEI_001</id>
    <title>土石流</title>
    <author><name>農業部</name></author>
    <link rel="alternate" href="https://alerts.test/cap/taipei.cap" />
    <summary type="html">重複條目（多語系重發）。</summary>
    <cap:effective>2026/7/6 下午 02:17:34</cap:effective>
    <cap:expires>2026/7/8 下午 05:00:00</cap:expires>
  </entry>
</feed>
XML;
    }

    private function capXml(string $areaDesc): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
  <identifier>x</identifier>
  <info>
    <severity>Moderate</severity>
    <area><areaDesc>{$areaDesc}</areaDesc></area>
    <area><areaDesc>新北市三峽區</areaDesc></area>
  </info>
</alert>
XML;
    }

    private function fakeSources(): void
    {
        Http::fake([
            'alerts.ncdr.nat.gov.tw/*' => Http::response($this->feedXml()),
            'alerts.test/cap/taipei.cap' => Http::response($this->capXml('臺北市北投區')),
            'alerts.test/cap/south.cap' => Http::response($this->capXml('高雄市楠梓區')),
        ]);
    }

    public function test_new_alerts_are_ingested_with_taipei_coverage_flag(): void
    {
        $this->fakeSources();

        $this->artisan('civicshield:fetch-ncdr-alerts')->assertSuccessful();

        // feed 內重複 cap_id 只入庫一次
        $this->assertSame(2, ExternalAlert::count());

        $taipei = ExternalAlert::where('cap_id', 'CAP_TAIPEI_001')->sole();
        $this->assertTrue($taipei->covers_taipei);
        $this->assertSame('土石流', $taipei->alert_type);
        $this->assertSame('Moderate', $taipei->severity);
        $this->assertContains('臺北市北投區', $taipei->areas);
        // 中文上下午時間解析（下午 05:00 → 17:00 台北時間）
        $this->assertSame('2026-07-08 17:00:00', $taipei->expires_at->timezone('Asia/Taipei')->format('Y-m-d H:i:s'));

        $south = ExternalAlert::where('cap_id', 'CAP_SOUTH_001')->sole();
        $this->assertFalse($south->covers_taipei);
        // 上午 12:30 = 00:30
        $this->assertSame('00:30', $south->expires_at->timezone('Asia/Taipei')->format('H:i'));

        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('ncdr_alerts')['status']);
    }

    public function test_rerun_does_not_duplicate_alerts(): void
    {
        $this->fakeSources();

        $this->artisan('civicshield:fetch-ncdr-alerts')->assertSuccessful();
        $this->artisan('civicshield:fetch-ncdr-alerts')->assertSuccessful();

        $this->assertSame(2, ExternalAlert::count());
    }

    public function test_feed_failure_degrades_health_without_throwing(): void
    {
        Http::fake(['alerts.ncdr.nat.gov.tw/*' => Http::response('oops', 503)]);

        $this->artisan('civicshield:fetch-ncdr-alerts')->assertFailed();

        // 驗收 §1-5：失敗必須記錄並反映在狀態燈，不得默默跳過
        $status = app(DataSourceHealth::class)->status('ncdr_alerts');
        $this->assertSame(DataSourceHealth::STATUS_DOWN, $status['status']);
        $this->assertNotNull($status['last_error']);
    }

    public function test_active_taipei_alert_appears_in_map_ticker(): void
    {
        ExternalAlert::create([
            'source' => 'NCDR', 'cap_id' => 'T1', 'alert_type' => '土石流',
            'headline' => '大台北地區土石流黃色警戒', 'covers_taipei' => true,
            'effective_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        ExternalAlert::create([
            'source' => 'NCDR', 'cap_id' => 'T2', 'alert_type' => '停水',
            'headline' => '已過期警戒不播', 'covers_taipei' => true,
            'effective_at' => now()->subDays(2), 'expires_at' => now()->subDay(),
        ]);

        $ticker = Livewire::test('radar-map')->get('tickerMessages');

        // 驗收 §1-2：新警戒（未過期 + 涵蓋台北市）進跑馬燈；過期不播
        $this->assertTrue(collect($ticker)->contains(fn ($m) => str_contains($m, '大台北地區土石流黃色警戒')));
        $this->assertFalse(collect($ticker)->contains(fn ($m) => str_contains($m, '已過期警戒不播')));
    }
}
