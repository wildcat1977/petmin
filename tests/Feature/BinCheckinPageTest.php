<?php

namespace Tests\Feature;

use App\Models\TrashBin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 巡桶打卡頁 /b/{bin}：NFC 貼紙與 QR Code 指向的網址。 */
class BinCheckinPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkin_page_shows_bin_and_report_button(): void
    {
        $this->withoutVite();
        $bin = TrashBin::factory()->create(['external_id' => 'clean_box-abc123', 'address' => '北平東路口', 'district' => '中正區', 'last_verified_at' => now()->subDays(2)]);

        $this->get('/b/clean_box-abc123?via=nfc')
            ->assertOk()
            ->assertSee('打卡成功')
            ->assertSee('北平東路口')
            ->assertSee('2 天前有人確認還在')
            ->assertSee('回報這個桶的狀況')
            ->assertSee("binId: {$bin->id}", false);
    }

    public function test_official_bin_is_not_reachable_by_database_id(): void
    {
        // 貼紙一律寫官方編號；官方桶不能用各環境不同的自動編號打開，免得指錯桶
        $bin = TrashBin::factory()->create(['external_id' => 'clean_box-abc123']);

        $this->get("/b/{$bin->id}")->assertNotFound();
    }

    public function test_crowd_bin_without_official_code_uses_database_id(): void
    {
        $this->withoutVite();
        $bin = TrashBin::factory()->create(['external_id' => null, 'source' => TrashBin::SOURCE_CROWD, 'address' => '市民新增的桶']);

        $this->get("/b/{$bin->id}")->assertOk()->assertSee('市民新增的桶');
        $this->get('/b/no-such-bin')->assertNotFound();
    }
}
