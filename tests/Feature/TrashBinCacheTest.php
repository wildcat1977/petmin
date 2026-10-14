<?php

namespace Tests\Feature;

use App\Models\TrashBin;
use App\Services\BagBoxGapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 正式機 7 個站共用同一個 Redis 快取 DB：Cache::flush() 在 Redis 上是 FLUSHDB，會把別站的快取、
 * 排程互斥鎖、限流計數一起清掉。桶位異動只能清自己相關的鍵。
 */
class TrashBinCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_bin_does_not_wipe_unrelated_cache_keys(): void
    {
        Cache::put('gotcha-cache-some-key', 'other site data', 600);

        TrashBin::factory()->create();

        $this->assertSame('other site data', Cache::get('gotcha-cache-some-key'));
    }

    /** 桶位異動後「有袋沒桶」要重算（快取鍵帶距離門檻，清錯鍵等於沒清） */
    public function test_saving_a_bin_refreshes_bag_box_gaps(): void
    {
        $this->assertCount(0, app(BagBoxGapService::class)->gaps());

        TrashBin::factory()->dogBagBox()->create(['latitude' => 25.0330, 'longitude' => 121.5654]);

        $this->assertCount(1, app(BagBoxGapService::class)->gaps());
    }

    /**
     * 正式機 Redis 會序列化快取，且 serializable_classes=false 不還原物件：快取裡放 Collection，
     * 第二次讀回來是 __PHP_Incomplete_Class，候選頁直接 500。快取只能放純陣列。
     */
    public function test_bag_box_gaps_survive_a_serializing_cache_store(): void
    {
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        TrashBin::factory()->dogBagBox()->create(['latitude' => 25.0330, 'longitude' => 121.5654]);

        app(BagBoxGapService::class)->gaps();          // 寫入快取
        $again = app(BagBoxGapService::class)->gaps(); // 從快取讀回

        $this->assertCount(1, $again);
        $this->assertSame(25.033, $again->first()['lat']);
    }
}
