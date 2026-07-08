{{--
    共用 footer（首頁 + 各公開頁通用，v0.3 二次回饋 item 2/6）：
    左：各分類最新消息篇數（只列有已發佈者，連 /news）；右：資料源健康燈號 + 年份。
    自給自足（内部自算 newsCounts/health），任何頁面 @include 即可，不需 route 傳資料。
--}}
@php
    $footerNewsCounts = \App\Models\Announcement::publishedCountsByCategory();
    $footerHealth = collect(\App\Services\DataSourceHealth::SOURCE_LABELS)
        ->map(fn ($label, $source) => [...app(\App\Services\DataSourceHealth::class)->status($source), 'label' => $label])
        ->values();
@endphp

<footer class="mx-auto flex w-full max-w-3xl flex-wrap items-center justify-between gap-x-4 gap-y-1.5 text-[11px] text-slate-400">
    @if (! empty($footerNewsCounts))
        <a href="{{ route('news') }}" class="flex flex-wrap items-center gap-x-2 gap-y-0.5 hover:text-slate-200">
            @foreach ($footerNewsCounts as $cat => $n)
                <span>{{ __(\App\Models\Announcement::CATEGORY_LABELS[$cat] ?? $cat) }} {{ $n }}</span>
            @endforeach
        </a>
    @else
        <a href="{{ route('news') }}" class="hover:text-slate-200">{{ __('最新消息') }}</a>
    @endif

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        @foreach ($footerHealth as $source)
            <span class="flex items-center gap-1" title="{{ $source['last_success_at'] ? \Illuminate\Support\Carbon::parse($source['last_success_at'])->timezone('Asia/Taipei')->format('m/d H:i') : __('尚無成功紀錄') }}">
                <span class="h-1.5 w-1.5 rounded-full {{ match ($source['status']) {
                    'green' => 'bg-emerald-400',
                    'yellow' => 'bg-yellow-400 animate-pulse',
                    default => 'bg-rose-500 animate-pulse',
                } }}"></span>
                <span class="text-slate-500">{{ $source['label'] }}</span>
            </span>
        @endforeach
        <span class="text-slate-600">© {{ date('Y') }} Hazdar</span>
    </div>
</footer>
