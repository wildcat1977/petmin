<?php

namespace Tests\Feature\Commands;

use App\Models\TrashBin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * open data 匯入（讀本機 CSV，不依賴網路）：upsert 冪等、壞列跳過不中斷、
 * 既有桶的眾包驗證狀態不被官方資料覆寫（否則每天重匯就把「消失」洗回「使用中」）。
 */
class ImportTrashBinsTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_boxes_are_imported_with_geohash_and_bad_rows_are_skipped(): void
    {
        $this->artisan('bins:import-clean-boxes', ['--file' => base_path('tests/fixtures/clean_boxes.csv')])
            ->expectsOutputToContain('匯入 2 筆（略過 1）')
            ->assertSuccessful();

        $this->assertSame(2, TrashBin::count());
        $bin = TrashBin::where('address', '公園路與愛國西路口')->sole();
        $this->assertSame(TrashBin::KIND_CLEAN_BOX, $bin->kind);
        $this->assertSame(TrashBin::SOURCE_OFFICIAL_CLEAN_BOX, $bin->source);
        $this->assertSame('中正區', $bin->district);
        $this->assertSame(TrashBin::STATUS_ACTIVE, $bin->status);
        $this->assertSame(7, strlen($bin->geohash7));
    }

    public function test_reimport_is_idempotent_and_keeps_crowd_verified_status(): void
    {
        $file = base_path('tests/fixtures/clean_boxes.csv');
        $this->artisan('bins:import-clean-boxes', ['--file' => $file])->assertSuccessful();
        TrashBin::where('address', '公園路與愛國西路口')->sole()->update(['status' => TrashBin::STATUS_MISSING]);

        $this->artisan('bins:import-clean-boxes', ['--file' => $file])->assertSuccessful();

        $this->assertSame(2, TrashBin::count());
        $this->assertSame(TrashBin::STATUS_MISSING, TrashBin::where('address', '公園路與愛國西路口')->sole()->status);
    }

    public function test_dog_bag_boxes_parse_box_count_from_note(): void
    {
        $this->artisan('bins:import-dog-bag-boxes', ['--file' => base_path('tests/fixtures/dog_bag_boxes.csv')])->assertSuccessful();

        $box = TrashBin::where('address', '士林捷運站')->sole();
        $this->assertSame(TrashBin::KIND_DOG_BAG_BOX, $box->kind);
        $this->assertSame(14, $box->box_count);
        $this->assertSame(2, TrashBin::where('address', '華山公園')->sole()->box_count);
    }

    public function test_missing_file_fails_loudly(): void
    {
        $this->artisan('bins:import-clean-boxes', ['--file' => '/nonexistent.csv'])
            ->expectsOutputToContain('找不到檔案')
            ->assertFailed();
    }

    public function test_bundled_open_data_snapshots_import(): void
    {
        // repo 內附的真實 open data 快照必須能被匯入（提案 demo 靠它）
        $this->artisan('bins:import-clean-boxes')->assertSuccessful();
        $this->artisan('bins:import-dog-bag-boxes')->assertSuccessful();

        $this->assertGreaterThan(600, TrashBin::kind(TrashBin::KIND_CLEAN_BOX)->count());
        $this->assertGreaterThan(100, TrashBin::kind(TrashBin::KIND_DOG_BAG_BOX)->count());
    }
}
