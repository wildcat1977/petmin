<?php

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateReportPhotoVariants;
use App\Support\ReportPhotoVariants;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Interfaces\ImageInterface;
use Tests\TestCase;

/**
 * 回報照片上傳只重新編碼、不縮小，手機原圖動輒數 MB；地圖彈窗只有 260×180（2026-10-01）。
 * 點位 API 讀到已公開照片時才於背景產生 -md（長邊 1280），不改上傳流程（做法同洋流好店）。
 */
class GenerateReportPhotoVariantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function putJpeg(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image, null, 90);
        Storage::disk('public')->put($path, ob_get_clean());
    }

    public function test_job_writes_the_medium_scaled_down_to_its_long_edge(): void
    {
        $this->putJpeg('reports/a.jpg', 4000, 3000);

        (new GenerateReportPhotoVariants('reports/a.jpg'))->handle();

        $this->assertSame([1280, 960], array_slice(getimagesizefromstring(Storage::disk('public')->get('reports/a-md.jpg')), 0, 2));
    }

    public function test_small_originals_are_not_upscaled(): void
    {
        // 放大只會變糊又變大，小圖維持原尺寸
        $this->putJpeg('reports/small.jpg', 200, 100);

        (new GenerateReportPhotoVariants('reports/small.jpg'))->handle();

        $this->assertSame([200, 100], array_slice(getimagesizefromstring(Storage::disk('public')->get('reports/small-md.jpg')), 0, 2));
    }

    public function test_missing_original_is_skipped_quietly(): void
    {
        // 排入佇列後照片被換掉或刪掉很正常，不可讓 job 失敗重試
        (new GenerateReportPhotoVariants('reports/gone.jpg'))->handle();

        Storage::disk('public')->assertMissing('reports/gone-md.jpg');
    }

    /**
     * 解碼期間原圖被刪（例如後台處理下架）：寫完要再確認原圖還在，
     * 否則縮圖會以 -md 永遠留在公開網址上。
     */
    public function test_medium_is_removed_when_the_original_is_deleted_while_processing(): void
    {
        $this->putJpeg('reports/a.jpg', 800, 600);
        $job = new class('reports/a.jpg') extends GenerateReportPhotoVariants
        {
            protected function decode(string $absolutePath): ImageInterface
            {
                $image = parent::decode($absolutePath);
                Storage::disk('public')->delete($this->path);

                return $image;
            }
        };

        $job->handle();

        Storage::disk('public')->assertMissing('reports/a-md.jpg');
    }

    public function test_the_same_photo_is_queued_at_most_once_at_a_time(): void
    {
        // 點位 API 每次移動地圖都會檢查，佇列跑完前同一張照片不可堆出多個 job
        $job = new GenerateReportPhotoVariants('reports/a.jpg');

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('reports/a.jpg', $job->uniqueId());
    }

    public function test_the_uniqueness_lock_expires_so_a_lost_job_does_not_block_forever(): void
    {
        // 沒有 TTL 的話，job 遺失（佇列被清、worker 被砍）時鎖永遠不放，那張照片再也不會有縮圖
        $this->assertSame(3600, (new GenerateReportPhotoVariants('reports/a.jpg'))->uniqueFor);
    }

    public function test_medium_path_follows_the_original_name(): void
    {
        $this->assertSame('reports/a-md.jpg', ReportPhotoVariants::path('reports/a.jpg', 'md'));
        $this->assertSame('reports/b-md.jpg', ReportPhotoVariants::path('reports/b.png', 'md'));
    }
}
