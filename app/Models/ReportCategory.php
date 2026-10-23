<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 回報分類（spec §11.7）：後台可增/停/改名/改色的唯一事實來源。
 * 讀取走 60s 快取（前台每個請求都要用），寫入即 flush；
 * slug 為程式鍵（reports.category 儲存值），建立後不可改。
 */
class ReportCategory extends Model
{
    use HasFactory;

    // v2：快取只存純陣列（見 allOrdered），換版號避開 v1 的物件序列化 blob
    private const CACHE_KEY = 'petmin:report-categories:v2';

    protected $fillable = ['slug', 'name', 'name_en', 'emoji', 'color', 'sort_order', 'is_active', 'extra_field'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'extra_field' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * 全部分類（含停用），依 sort_order；60s 快取。
     *
     * 快取只存純陣列、讀取時 hydrate：序列化 Eloquent 物件會在寫入端
     * （CLI 排程/Horizon worker）與讀取端（FPM）autoload 狀態不一致時
     * 反序列化成 __PHP_Incomplete_Class 而 500，純陣列不依賴任何 class。
     */
    public static function allOrdered(): Collection
    {
        $rows = Cache::remember(
            self::CACHE_KEY,
            60,
            fn () => static::query()->orderBy('sort_order')->get()->map(fn (self $c) => $c->getAttributes())->all()
        );

        if (! is_array($rows)) { // 防禦：非預期 blob 一律重建
            Cache::forget(self::CACHE_KEY);

            return static::query()->orderBy('sort_order')->get()->toBase();
        }

        return static::hydrate($rows)->toBase();
    }

    public static function activeOrdered(): Collection
    {
        return self::allOrdered()->where('is_active', true)->values();
    }

    /** 顯示名稱：英文版網站用 name_en，沒填就退回中文 */
    public function label(): string
    {
        return (app()->getLocale() === 'en' && filled($this->name_en)) ? $this->name_en : $this->name;
    }

    /** @return array<string, string> slug => 顯示名稱（依目前語系；含停用，舊點位仍需標籤） */
    public static function labels(): array
    {
        return self::allOrdered()->mapWithKeys(fn (self $c) => [$c->slug => $c->label()])->all();
    }

    /** @return list<string> 全部 slug（讀取端白名單：停用分類的既有點位仍可篩選） */
    public static function slugs(): array
    {
        return self::allOrdered()->pluck('slug')->all();
    }

    /** @return list<string> active slug（寫入端白名單：停用即關閉新回報） */
    public static function activeSlugs(): array
    {
        return self::activeOrdered()->pluck('slug')->all();
    }

    public static function defaultSlug(): string
    {
        return self::activeSlugs()[0] ?? 'flood';
    }

    public static function bySlug(string $slug): ?self
    {
        return self::allOrdered()->firstWhere('slug', $slug);
    }
}
