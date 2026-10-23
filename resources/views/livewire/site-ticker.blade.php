<?php

use App\Models\Report;
use App\Models\ReportCategory;
use App\Models\TrashBin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * 全站跑馬燈訊息：獨立元件讓次要頁面不必掛整個地圖也能共用 head nav 跑馬燈。
 * 30s 快取——訊息是 JS 端一次性讀入後前端輪播，非 live push。
 */
new class extends Component
{
    public function getMessagesProperty(): array
    {
        return Cache::remember('petmin:site-ticker', 30, function () {
            $messages = [];

            // 「登記」只算環保局逐點清單：景點商圈清單一筆是一個地點（N 處），混著加沒有意義
            $active = TrashBin::query()->active()->where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX)->count();
            // 消失＝民眾回報核准確認＋官方清單撤除
            $missing = TrashBin::query()->kind(TrashBin::KIND_CLEAN_BOX)->where('status', TrashBin::STATUS_MISSING)->count();
            if ($active > 0) {
                $messages[] = "🗑️ 台北市目前登記 {$active} 組行人專用清潔箱".($missing > 0 ? "，已確認消失 {$missing} 組" : '');
            }

            // 跑馬燈句子是中文、快取不分語系，分類名也固定用中文（labels() 會跟著訪客語系變）
            $names = ReportCategory::allOrdered()->pluck('name', 'slug');

            // 近 24h 各分類統計
            Report::query()
                ->visible()
                ->unresolved()
                ->where('created_at', '>=', now()->subDay())
                ->selectRaw('category, count(*) as n')
                ->groupBy('category')
                ->pluck('n', 'category')
                ->each(function ($n, $category) use (&$messages, $names) {
                    $label = $names[$category] ?? $category;
                    $messages[] = "📊 近 24 小時已收 {$n} 筆「{$label}」回報";
                });

            // 最近有描述的回報
            Report::query()
                ->visible()
                ->unresolved()
                ->whereNotNull('description')
                ->latest()
                ->limit(6)
                ->get(['category', 'description', 'created_at'])
                ->each(function ($r) use (&$messages, $names) {
                    $label = $names[$r->category] ?? $r->category;
                    $messages[] = '🐾 '.$r->created_at->diffForHumans()
                        ."，市民回報「{$label}」：".Str::limit($r->description, 25);
                });

            return $messages ?: ['🐾 遛狗順手回報，幫台北找回消失的垃圾桶'];
        });
    }
}; ?>

<div id="site-ticker" wire:ignore data-ticker='@json($this->messages)'></div>
