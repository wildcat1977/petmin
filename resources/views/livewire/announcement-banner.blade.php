<?php

use App\Models\Announcement;
use Livewire\Component;

new class extends Component
{
    public function render()
    {
        return view('livewire.announcement-banner', [
            'announcements' => Announcement::active()->latest()->get(),
        ]);
    }
}; ?>

<div>
    @foreach ($announcements as $announcement)
        <div
            x-data="{ show: true }"
            x-init="
                const key = 'ann_dismissed_{{ $announcement->id }}';
                if (sessionStorage.getItem(key)) show = false;
            "
            x-show="show"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="mx-auto w-full max-w-md rounded-2xl bg-[#111827]/90 px-4 py-3 ring-1 ring-blue-400/40 shadow-lg backdrop-blur"
        >
            <div class="flex items-start gap-3">
                <span class="mt-0.5 shrink-0 text-lg leading-none">📢</span>
                <div class="min-w-0 flex-1">
                    @if ($announcement->title)
                        <p class="text-xs font-semibold text-blue-300">{{ $announcement->title }}</p>
                    @endif
                    @php
                        // 富文本 body 為單一內容來源；彈窗僅顯示 strip 後前 30 字摘要（fallback 舊 content）
                        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($announcement->body ?: (string) $announcement->content)));
                        $truncated = mb_strlen($plain) > 30;
                    @endphp
                    <p class="mt-0.5 text-[12px] leading-relaxed text-slate-300">{{ mb_substr($plain, 0, 30) }}@if ($truncated)…&nbsp;<a href="{{ route('news') }}" class="font-medium text-blue-300 underline decoration-blue-300/40 underline-offset-2 hover:text-blue-200">[{{ __('詳見最新消息') }}]</a>@endif</p>
                </div>
                <button
                    type="button"
                    @click="show = false; sessionStorage.setItem('ann_dismissed_{{ $announcement->id }}', '1')"
                    class="shrink-0 text-slate-500 hover:text-slate-300 leading-none mt-0.5"
                    aria-label="關閉">✕</button>
            </div>
        </div>
    @endforeach
</div>
