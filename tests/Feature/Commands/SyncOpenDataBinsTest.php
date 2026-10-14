<?php

namespace Tests\Feature\Commands;

use App\Models\DemandCell;
use App\Models\TrashBin;
use App\Services\BagBoxGapService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 官方桶位每日同步（bins:sync-open-data）：清潔箱逐點清單、景點商圈清潔箱、狗便清潔袋箱三個來源。
 * 地圖與需求分數都建立在這份資料上，所以：髒座標要救回、重複的桶不能重複扣分、
 * 民眾驗證過的狀態不能被官方資料洗掉、官方撤除要留下證據、殘缺的 API 回應不能把整批桶誤標消失。
 */
class SyncOpenDataBinsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return base_path("tests/fixtures/open_data/{$name}");
    }

    private function useSnapshots(array $override = []): void
    {
        foreach (['clean_boxes' => 'clean_boxes.json', 'clean_box_areas' => 'clean_box_areas.json', 'dog_bag_boxes' => 'dog_bag_boxes.csv'] as $key => $file) {
            config(["petmin.open_data.sources.{$key}.snapshot" => $override[$key] ?? $this->fixture($file)]);
        }
    }

    /** 寫一份 data.taipei API 格式的清潔箱 JSON（$rows: [地址, 經度, 緯度]） */
    private function cleanBoxJson(array $rows, ?int $count = null): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cb');
        $results = array_map(fn ($r, $i) => ['_id' => $i + 1, '行政區' => '中正區', '地址' => $r[0], '經度' => (string) $r[1], '緯度' => (string) $r[2], '備註' => ''], $rows, array_keys($rows));
        file_put_contents($file, json_encode(['result' => ['count' => $count ?? count($rows), 'results' => $results]], JSON_UNESCAPED_UNICODE));

        return $file;
    }

    public function test_offline_sync_imports_all_three_sources_and_cleans_dirty_rows(): void
    {
        $this->useSnapshots();

        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();

        // 清潔箱：經度前夾「?」的列要救回，座標空白的列才跳過
        $clean = TrashBin::where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX)->get();
        $this->assertCount(3, $clean);
        $this->assertEqualsWithDelta(121.56781, (float) $clean->firstWhere('address', '松仁路與松高路口')->longitude, 1e-6);

        // 景點商圈：離逐點清單 100m 內的是同一批桶，跳過；來源裡重複的列只算一次；其餘以清潔箱計、組數取「N處」
        $areas = TrashBin::where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX_AREA)->get();
        $this->assertSame(['華山公園'], $areas->pluck('address')->all());
        $this->assertSame(TrashBin::KIND_CLEAN_BOX, $areas->first()->kind);
        $this->assertSame(2, $areas->first()->box_count);

        // 狗便袋箱：Big5 原檔、地址＝路名＋位置去掉箱編、箱編當穩定識別碼；
        // 「0000」這種多個袋箱共用的佔位箱編不能當識別碼，否則兩個袋箱會被當成同一個
        $bags = TrashBin::kind(TrashBin::KIND_DOG_BAG_BOX)->get()->keyBy('external_id');
        $this->assertCount(5, $bags);
        $this->assertFalse($bags->has('dog_bag_box-0000'));
        $this->assertSame('南京東路4段2號捷運台北小巨蛋2號出口', $bags['dog_bag_box-2058']->address);
        $this->assertSame('松山區', $bags['dog_bag_box-2058']->district);

        // 有異動就重算需求分數（袋箱是遛狗需求訊號）
        $this->assertGreaterThan(0, DemandCell::count());
    }

    /** 同步後「有袋沒桶」要立刻反映新桶位，不能拿到同步前的快取（候選頁與行政區排行都讀它） */
    public function test_sync_refreshes_cached_bag_box_gaps(): void
    {
        $this->assertCount(0, app(BagBoxGapService::class)->gaps()); // 先把空結果放進快取
        $this->useSnapshots();

        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();

        $this->assertNotEmpty(app(BagBoxGapService::class)->gaps());
    }

    /** 每天重跑不能多出重複的桶，也不能把民眾回報確認消失的桶洗回「使用中」 */
    public function test_resync_is_idempotent_and_keeps_crowd_verified_status(): void
    {
        $this->useSnapshots();
        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();
        TrashBin::where('address', '公園路與愛國西路口')->sole()->update(['status' => TrashBin::STATUS_MISSING]);

        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();

        $this->assertSame(9, TrashBin::count());
        $this->assertSame(TrashBin::STATUS_MISSING, TrashBin::where('address', '公園路與愛國西路口')->sole()->status);
    }

    /** 官方清單拿掉的桶＝「消失的桶」的官方證據：標已確認消失並記日期；之後又出現就恢復 */
    public function test_bin_removed_from_official_list_is_marked_missing_and_restored_when_back(): void
    {
        $full = $this->cleanBoxJson([['甲路口', 121.51, 25.04], ['乙路口', 121.52, 25.04], ['丙路口', 121.53, 25.04]]);
        $this->useSnapshots(['clean_boxes' => $full]);
        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();

        $this->useSnapshots(['clean_boxes' => $this->cleanBoxJson([['甲路口', 121.51, 25.04], ['乙路口', 121.52, 25.04]])]);
        $this->artisan('bins:sync-open-data', ['--offline' => true])->expectsOutputToContain('官方撤除 1')->assertSuccessful();

        $gone = TrashBin::where('address', '丙路口')->sole();
        $this->assertSame(TrashBin::STATUS_MISSING, $gone->status);
        $this->assertNotNull($gone->removed_from_source_at);

        $this->useSnapshots(['clean_boxes' => $full]);
        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();

        $back = $gone->fresh();
        $this->assertSame(TrashBin::STATUS_ACTIVE, $back->status);
        $this->assertNull($back->removed_from_source_at);
    }

    /** 環保局微調座標（同地址 30m 內）是同一個桶：不能變成「舊桶撤除＋新桶」 */
    public function test_small_coordinate_edit_with_same_address_keeps_the_same_bin(): void
    {
        $this->useSnapshots(['clean_boxes' => $this->cleanBoxJson([['甲路口', 121.51, 25.04], ['乙路口', 121.52, 25.04]])]);
        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();
        $id = TrashBin::where('address', '甲路口')->sole()->id;

        $this->useSnapshots(['clean_boxes' => $this->cleanBoxJson([['甲路口', 121.5101, 25.0401], ['乙路口', 121.52, 25.04]])]); // 約 15m
        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();

        $bin = TrashBin::where('address', '甲路口')->sole();
        $this->assertSame($id, $bin->id);
        $this->assertSame(TrashBin::STATUS_ACTIVE, $bin->status);
        $this->assertEqualsWithDelta(121.5101, (float) $bin->longitude, 1e-6);
    }

    /** 一次消失超過一成多半是 API 回傳殘缺：不套用撤除、大聲失敗，桶維持原狀 */
    public function test_mass_disappearance_is_not_applied_and_fails_loudly(): void
    {
        $rows = array_map(fn ($i) => ["第{$i}路口", 121.50 + $i * 0.001, 25.04], range(1, 40));
        $this->useSnapshots(['clean_boxes' => $this->cleanBoxJson($rows)]);
        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();

        $this->useSnapshots(['clean_boxes' => $this->cleanBoxJson(array_slice($rows, 0, 30))]);
        $this->artisan('bins:sync-open-data', ['--offline' => true])->expectsOutputToContain('不套用撤除')->assertFailed();

        $this->assertSame(0, TrashBin::where('status', TrashBin::STATUS_MISSING)->count());
    }

    /** API 只回了部分筆數（count 與 results 不符）：整個來源不動 */
    public function test_truncated_api_response_leaves_source_untouched(): void
    {
        $this->useSnapshots();
        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();

        $this->useSnapshots(['clean_boxes' => $this->cleanBoxJson([['公園路與愛國西路口', 121.5172055, 25.0384804]], count: 681)]);
        $this->artisan('bins:sync-open-data', ['--offline' => true])->expectsOutputToContain('1/681')->assertFailed();

        $this->assertSame(0, TrashBin::where('status', TrashBin::STATUS_MISSING)->count());
    }

    /** 線上模式抓 data.taipei；單一來源掛掉不影響其他來源，但整體要回報失敗讓排程記錄 */
    public function test_online_sync_fetches_sources_and_isolates_failures(): void
    {
        Http::fake([
            '*267d550f-c6ec-46e0-b8af-fd5a464eb098*' => Http::response(file_get_contents($this->fixture('clean_boxes.json'))),
            '*2030d728-9597-437d-81a4-cf2a44c7f114*' => Http::response('', 503),
            '*2822f947-7585-4da4-a1cf-f20d1475d4f8*' => Http::response(file_get_contents($this->fixture('dog_bag_boxes.csv'))),
        ]);

        $this->artisan('bins:sync-open-data')->expectsOutputToContain('503')->assertFailed();

        $this->assertSame(3, TrashBin::where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX)->count());
        $this->assertSame(5, TrashBin::kind(TrashBin::KIND_DOG_BAG_BOX)->count());
        $this->assertSame(0, TrashBin::where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX_AREA)->count());
    }

    /** repo 內附的官方快照必須能完整匯入（本機開發與 demo 靠它） */
    public function test_bundled_snapshots_sync(): void
    {
        $this->artisan('bins:sync-open-data', ['--offline' => true])->assertSuccessful();

        $this->assertSame(681, TrashBin::where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX)->count());
        $this->assertGreaterThan(80, TrashBin::where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX_AREA)->count());
        $this->assertGreaterThan(450, TrashBin::kind(TrashBin::KIND_DOG_BAG_BOX)->count());
    }

    public function test_sync_is_scheduled_daily_at_4am(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'bins:sync-open-data'));

        $this->assertNotNull($event, '同步指令沒有排程');
        $this->assertSame('0 4 * * *', $event->expression);
    }
}
