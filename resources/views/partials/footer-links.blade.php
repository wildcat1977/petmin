{{--
    共用 footer（首頁 + 各公開頁通用）：
    左：各分類最新消息篇數（只列有已發佈者，連 /news）；右：資料來源 + 年份。
    自給自足，任何頁面 @include 即可。
--}}
@php
    $footerNewsCounts = \App\Models\Announcement::publishedCountsByCategory();
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
        <span class="text-slate-500">{{ __('資料：臺北市環保局 open data + 市民回報') }}</span>
        <span class="text-slate-600">© {{ date('Y') }} Petmin</span>
    </div>
</footer>
