<?php

namespace App\Jobs;

use App\Support\ReportPhotoVariants;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * 產生回報照片縮圖（ReportPhotoVariants）。唯一鍵是原圖路徑：點位 API 每次移動地圖都會檢查，
 * 佇列跑完前同一張照片不可堆出多個 job。點位 API 快取只有數十秒，產生完不必清快取。
 */
class GenerateReportPhotoVariants implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * 唯一鎖的存活秒數。沒有 TTL 的話，job 遺失（佇列被清、worker 被砍）時鎖永遠不放，
     * 那張照片再也不會被排入；過期後下一次讀取點位會自然重排。
     */
    public int $uniqueFor = 3600;

    public function __construct(public string $path) {}

    public function uniqueId(): string
    {
        return $this->path;
    }

    public function handle(): void
    {
        $disk = Storage::disk('public');

        // 排入佇列後照片被換掉或刪掉很正常，不可讓 job 失敗重試
        if (! $disk->exists($this->path)) {
            return;
        }

        // 只解碼一次，由大到小處理：scaleDown 會就地改動影像，小尺寸從上一個縮好的結果再縮
        $image = $this->decode($disk->path($this->path));
        $sizes = ReportPhotoVariants::SIZES;
        arsort($sizes);

        foreach ($sizes as $variant => $longEdge) {
            $image->scaleDown(width: $longEdge, height: $longEdge);
            $disk->put(ReportPhotoVariants::path($this->path, $variant), (string) $image->encode(new JpegEncoder(quality: 80)));
        }

        // 解碼、編碼期間原圖被刪（例如下架）：寫完再確認一次原圖還在，否則縮圖會永遠留在公開網址上
        if (! $disk->exists($this->path)) {
            ReportPhotoVariants::delete($this->path);
        }
    }

    protected function decode(string $absolutePath): ImageInterface
    {
        return (new ImageManager(new GdDriver))->decode($absolutePath);
    }
}
