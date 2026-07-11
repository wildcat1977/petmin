<?php

namespace Tests\Feature\Commands;

use App\Models\ExternalAlert;
use App\Services\DataSourceHealth;
use App\Support\CityInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * NCDR CAP 拉取（spec §5 / 驗收 §1-2）：新警戒 5 分鐘輪詢落地，
 * 涵蓋台北市判定於 ingest 時完成，跑馬燈只讀落地資料。
 * 失敗必須反映在健康狀態——禁止默默跳過（驗收 §1-5）。
 *
 * 韌性（2026-07-09，NCDR 依 IP 限流連發即 429）：停課 feed 與主 feed 解耦、
 * 停課涵蓋台北改用 headline 判定、429 退避重試。
 */
class FetchNcdrAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 停課 fixtures 用固定日期（停課至 2026/7/10），凍結時間避免真實日期跨過期限後
        // active() 把 fixture 濾掉造成 flaky（Laravel 於 tearDown 自動重置 test now）。
        Carbon::setTestNow('2026-07-09 08:00:00');
    }

    /** 主警報 feed（一般警戒，含 cap_link → 會抓 CAP 明細取 areaDesc）。 */
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

    /**
     * 停班停課 feed（AlertType=33）：範圍寫在 summary/headline、無 cap_link → 不抓明細；
     * 涵蓋台北僅能靠 headline 判定（真實 feed 即如此，明細常被限流）。
     */
    private function closureFeedXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:cap="urn:oasis:names:tc:emergency:cap:1.1">
  <id>https://alerts.ncdr.nat.gov.tw/RSS.aspx</id>
  <title>NCDR_CAP-停班停課</title>
  <entry>
    <id>CLOSURE_TPE_710</id>
    <title>停班停課</title>
    <author><name>NCDR</name></author>
    <summary type="html">臺北市:7/10停止上班、停止上課。行政院人事行政總處。</summary>
    <cap:effective>2026/7/9 下午 08:20:00</cap:effective>
    <cap:expires>2026/7/10 上午 08:00:00</cap:expires>
  </entry>
  <entry>
    <id>CLOSURE_CHIAYI_710</id>
    <title>停班停課</title>
    <author><name>NCDR</name></author>
    <summary type="html">嘉義縣梅山鄉:7/10停止上班、停止上課。</summary>
    <cap:effective>2026/7/9 下午 02:00:00</cap:effective>
    <cap:expires>2026/7/10 上午 08:00:00</cap:expires>
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

    /** 停課 feed（AlertType=33）與主 feed 各自回應；明細只有主 feed 會抓。 */
    private function fakeSources(): void
    {
        Http::fake([
            '*AlertType=33*' => Http::response($this->closureFeedXml()),
            'alerts.test/cap/taipei.cap' => Http::response($this->capXml('臺北市北投區')),
            'alerts.test/cap/south.cap' => Http::response($this->capXml('高雄市楠梓區')),
            'alerts.ncdr.nat.gov.tw/*' => Http::response($this->feedXml()),
        ]);
    }

    public function test_new_alerts_are_ingested_with_taipei_coverage_flag(): void
    {
        $this->fakeSources();

        $this->artisan('hazdar:fetch-ncdr-alerts')->assertSuccessful();

        // 停課 feed 2 筆 + 主 feed 2 筆（主 feed 內重複 cap_id 只入庫一次）
        $this->assertSame(4, ExternalAlert::count());

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

    /**
     * 停課 feed 不抓 CAP 明細（無 cap_link），涵蓋台北僅靠 headline——這是本次韌性重點：
     * 明細被 429 擋掉時，「臺北市:7/10停止上班」仍必須被標為涵蓋台北，banner 才顯示得出來。
     */
    public function test_closure_alert_detects_taipei_from_headline_without_cap_detail(): void
    {
        $this->fakeSources();

        $this->artisan('hazdar:fetch-ncdr-alerts')->assertSuccessful();

        $tpe = ExternalAlert::where('cap_id', 'CLOSURE_TPE_710')->sole();
        $this->assertSame('停班停課', $tpe->alert_type);
        $this->assertTrue($tpe->covers_taipei);   // 由 headline 判定，非 areaDesc
        $this->assertSame([], $tpe->areas);         // 未抓明細，areas 為空

        // 非台北的停課不誤標
        $chiayi = ExternalAlert::where('cap_id', 'CLOSURE_CHIAYI_710')->sole();
        $this->assertFalse($chiayi->covers_taipei);

        // CityInfo::closure() 應撈到台北這筆（未過期 + 涵蓋台北）
        $this->assertNotNull(CityInfo::closure());
    }

    /**
     * 主 feed 失敗時，停課 feed 仍必須先落地（解耦）——安全關鍵的停課不得被一般警戒 feed 的
     * 429 連坐吞掉。健康仍降級（有錯就記），但台北停課要進得了 DB。
     */
    public function test_main_feed_failure_still_lands_closure_alert(): void
    {
        Http::fake([
            '*AlertType=33*' => Http::response($this->closureFeedXml()),
            'alerts.ncdr.nat.gov.tw/*' => Http::response('rate limited', 429),
        ]);

        // 主 feed 429 → 整體記失敗
        $this->artisan('hazdar:fetch-ncdr-alerts')->assertFailed();

        // 但停課台北這筆已落地，且健康降級（不得默默跳過）
        $this->assertNotNull(ExternalAlert::where('cap_id', 'CLOSURE_TPE_710')->first());
        $this->assertNotNull(CityInfo::closure());
        $status = app(DataSourceHealth::class)->status('ncdr_alerts');
        $this->assertSame(DataSourceHealth::STATUS_DOWN, $status['status']);
        $this->assertNotNull($status['last_error']);
    }

    /** 429 為 NCDR 的 IP 限流常態；退避重試後成功不應記為失敗。 */
    public function test_retries_on_429_then_succeeds(): void
    {
        Http::fake([
            '*AlertType=33*' => Http::sequence()
                ->pushStatus(429)
                ->push($this->closureFeedXml(), 200),
            'alerts.ncdr.nat.gov.tw/*' => Http::response($this->feedXml()),
            'alerts.test/cap/taipei.cap' => Http::response($this->capXml('臺北市北投區')),
            'alerts.test/cap/south.cap' => Http::response($this->capXml('高雄市楠梓區')),
        ]);

        $this->artisan('hazdar:fetch-ncdr-alerts')->assertSuccessful();

        $this->assertNotNull(ExternalAlert::where('cap_id', 'CLOSURE_TPE_710')->first());
        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('ncdr_alerts')['status']);
    }

    public function test_rerun_does_not_duplicate_alerts(): void
    {
        $this->fakeSources();

        $this->artisan('hazdar:fetch-ncdr-alerts')->assertSuccessful();
        $this->artisan('hazdar:fetch-ncdr-alerts')->assertSuccessful();

        $this->assertSame(4, ExternalAlert::count());
    }

    public function test_feed_failure_degrades_health_without_throwing(): void
    {
        Http::fake(['alerts.ncdr.nat.gov.tw/*' => Http::response('oops', 503)]);

        $this->artisan('hazdar:fetch-ncdr-alerts')->assertFailed();

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

        // v0.3 三次回饋：跑馬燈邏輯自 radar-map 抽到獨立 site-ticker 元件（30s 快取），
        // 需先 forget 避免吃到別的測試留下的快取（同既有 hazdar:eoc:cards 作法）。
        Cache::forget('hazdar:site-ticker');
        $ticker = Livewire::test('site-ticker')->get('messages');

        // 驗收 §1-2：新警戒（未過期 + 涵蓋台北市）進跑馬燈；過期不播
        $this->assertTrue(collect($ticker)->contains(fn ($m) => str_contains($m, '大台北地區土石流黃色警戒')));
        $this->assertFalse(collect($ticker)->contains(fn ($m) => str_contains($m, '已過期警戒不播')));
    }
}
