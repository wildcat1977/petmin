<?php

use App\Models\Report;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function render()
    {
        $reports = collect();

        if ($user = Auth::user()) {
            $reports = Report::where('user_id', $user->id)
                ->whereNotNull('reviewed_at')
                ->whereIn('status', [
                    Report::STATUS_APPROVED,
                    Report::STATUS_REJECTED,
                ])
                ->orderByDesc('reviewed_at')
                ->limit(5)
                ->get();
        }

        return view('livewire.my-report-status', ['reports' => $reports]);
    }
}; ?>

<div>
@if(Auth::check() && isset($reports) && $reports->isNotEmpty())
<div
    x-data="{
        dismissed: JSON.parse(localStorage.getItem('petmin_reviews') || '{}'),
        isDismissed(id, ts) { return !!this.dismissed[id + '_' + ts]; },
        dismiss(id, ts) {
            this.dismissed[id + '_' + ts] = true;
            localStorage.setItem('petmin_reviews', JSON.stringify(this.dismissed));
        },
        hasVisible(reports) {
            return reports.some(r => !this.isDismissed(r.id, r.ts));
        }
    }"
    x-show="hasVisible({{ Js::from($reports->map(fn($r) => ['id' => $r->id, 'ts' => $r->reviewed_at?->timestamp ?? 0])->values()) }})"
    class="mx-auto w-full max-w-md space-y-2"
>
    @foreach($reports as $report)
    @php
        $ts = $report->reviewed_at?->timestamp ?? 0;
        $isApproved = $report->status === Report::STATUS_APPROVED;
        $isRejected = $report->status === Report::STATUS_REJECTED;
        $typeLabel  = \App\Models\ReportCategory::labels()[$report->category] ?? $report->category;
        $reasonText = $report->rejection_reason
            ? (Report::REJECTION_REASONS[$report->rejection_reason] ?? $report->rejection_reason)
            : null;
    @endphp
    <div
        x-show="!isDismissed({{ $report->id }}, {{ $ts }})"
        x-transition:leave="transition duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        class="relative flex items-start gap-3 rounded-2xl px-4 py-3 backdrop-blur ring-1
            {{ $isApproved ? 'bg-green-950/70 ring-green-500/30' : 'bg-red-950/70 ring-red-500/30' }}"
    >
        <span class="mt-0.5 text-xl leading-none">{{ $isApproved ? '✅' : '❌' }}</span>
        <div class="flex-1 min-w-0">
            <p class="text-xs font-semibold {{ $isApproved ? 'text-green-300' : 'text-red-300' }}">
                {{ __(':typeLabel 回報審核:result', ['typeLabel' => $typeLabel, 'result' => $isApproved ? __('通過') : __('未通過')]) }}
                <span class="ml-1 font-normal text-slate-500 text-[10px]">#{{ $report->id }}</span>
            </p>
            @if($isApproved)
                <p class="mt-0.5 text-[11px] text-slate-400">{{ __('照片已於地圖公開，感謝你的協助！') }}</p>
            @else
                <p class="mt-0.5 text-[11px] text-slate-400">
                    @if($reasonText)
                        {{ __('原因：:reason', ['reason' => $reasonText]) }}
                    @else
                        {{ __('圖片或資訊未符合回報規範') }}
                    @endif
                </p>
            @endif
            @if($report->address)
                <p class="mt-0.5 text-[10px] text-slate-600 truncate">{{ $report->address }}</p>
            @endif
        </div>
        <button
            @click="dismiss({{ $report->id }}, {{ $ts }})"
            class="absolute top-2 right-3 text-slate-600 hover:text-slate-300 text-xs leading-none"
            title="{{ __('關閉') }}">✕</button>
    </div>
    @endforeach
</div>
@endif
</div>
