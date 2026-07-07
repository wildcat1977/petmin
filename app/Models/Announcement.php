<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Announcement extends Model
{
    public const CATEGORY_LABELS = [
        'school_closure' => '停班停課',
        'home_safety' => '居家災防',
        'gov_press' => '市府與氣象署',
        'incident' => '災情通報',
    ];

    protected $fillable = [
        'title',
        'content',
        'is_active',
        'category',
        'body',
        'link_url',
        'is_pinned',
        'published_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_pinned' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** news 頁上稿判準：published_at 已到（與跑馬燈的 is_active 正交） */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * footer 用：各分類已發佈篇數（只列有篇數的分類），60s 快取，純陣列。
     * 自給自足供共用 footer partial 直接呼叫，不需每個 route 各自算一次。
     *
     * @return array<string, int>
     */
    public static function publishedCountsByCategory(): array
    {
        return Cache::remember(
            'hazdar:footer:news-counts',
            60,
            fn () => static::published()
                ->selectRaw('category, count(*) as c')
                ->groupBy('category')
                ->orderByRaw('count(*) desc')
                ->pluck('c', 'category')
                ->all()
        );
    }
}
