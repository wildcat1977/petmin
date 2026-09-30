<?php

namespace App\Support;

use App\Jobs\GenerateReportPhotoVariants;
use Illuminate\Support\Facades\Storage;

/**
 * 回報照片的縮圖（md：地圖點位彈窗）。
 *
 * 上傳只重新編碼、不縮小，手機原圖動輒數 MB，彈窗只有 260×180（2026-10-01）。縮圖不在上傳時產生：
 * 點位 API 讀到已公開照片時檢查，缺的先回原圖、排入背景產生，下次讀取自然就有
 * （做法同洋流好店 docs/basemap-spec.md §9；本站只需一種尺寸，見 docs/basemap.md）。
 *
 * 只能從已公開的讀取路徑呼叫（Report::publicImageUrl() 不為 null）：照片審核通過前不公開（spec §6.1-4）。
 */
final class ReportPhotoVariants
{
    /** 長邊像素上限；小於此尺寸的原圖不放大。彈窗 260px 寬在高解析螢幕需要 500px 以上，256 會糊 */
    public const SIZES = [
        'md' => 1280,
    ];

    public static function path(string $original, string $variant): string
    {
        return preg_replace('/\.[^.\/]+$/', '', $original)."-{$variant}.jpg";
    }

    /** md 的公開網址；還沒產生時排入背景產生並回 null（呼叫端退回原圖） */
    public static function mediumUrl(string $original): ?string
    {
        $disk = Storage::disk('public');
        $medium = self::path($original, 'md');

        if (! $disk->exists($medium)) {
            GenerateReportPhotoVariants::dispatch($original);

            return null;
        }

        return $disk->url($medium);
    }

    public static function delete(string $original): void
    {
        Storage::disk('public')->delete(array_map(fn (string $variant): string => self::path($original, $variant), array_keys(self::SIZES)));
    }
}
