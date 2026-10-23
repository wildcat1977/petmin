<?php

use App\Jobs\ModerateBinName;
use App\Models\BinName;
use App\Models\TrashBin;
use App\Services\BinNameModerationService;
use App\Services\BinOccupationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * 打卡頁的佔領榜：這個桶誰巡最多次（有效次數規則見 config petmin.bin_naming），第一名可以幫桶命名。
 * 名字當場過格式與黑名單，再丟 ModerateBinName 給 AI 審；通過前地圖上照掛舊名。
 */
new class extends Component
{
    public int $binId;

    public string $name = '';

    public function submit(BinOccupationService $occupation, BinNameModerationService $moderation): void
    {
        $this->resetErrorBag();
        if (! Auth::check()) {
            $this->dispatch('open-auth-onboarding');

            return;
        }

        $bin = TrashBin::findOrFail($this->binId);
        if ($occupation->leader($bin)?->id !== Auth::id()) {
            $this->addError('name', __('目前不是第一名，還不能命名'));

            return;
        }
        $mine = BinName::where('user_id', Auth::id());
        if ((clone $mine)->where('trash_bin_id', $bin->id)->where('status', BinName::STATUS_PENDING)->exists()) {
            $this->addError('name', __('上一個名字還在審核中'));

            return;
        }
        if ((clone $mine)->where('created_at', '>=', now()->subDay())->count() >= (int) config('petmin.bin_naming.daily_submissions')) {
            $this->addError('name', __('今天送審太多次了，明天再試'));

            return;
        }
        if ($error = $moderation->precheck($this->name)) {
            $this->addError('name', $error);

            return;
        }

        $binName = BinName::create([
            'trash_bin_id' => $bin->id,
            'user_id' => Auth::id(),
            'name' => $moderation->clean($this->name),
            'status' => BinName::STATUS_PENDING,
        ]);
        ModerateBinName::dispatch($binName->id);
        $this->reset('name');
    }

    public function with(BinOccupationService $occupation): array
    {
        $bin = TrashBin::with('currentName.user')->findOrFail($this->binId);
        $standings = $occupation->standings($bin);
        $min = (int) config('petmin.bin_naming.min_reports');
        $myCount = Auth::check() ? (int) ($standings->firstWhere('user.id', Auth::id())['count'] ?? 0) : 0;
        $top = $standings->first();

        return [
            'current' => $bin->currentName,
            'standings' => $standings->take(3),
            'min' => $min,
            'myCount' => $myCount,
            'isLeader' => Auth::check() && $top && $top['user']->id === Auth::id() && $top['count'] >= $min,
            // 自己最近一次送審：審核中要顯示、被退要讓本人知道原因（不給別人看）
            'mySubmission' => Auth::check()
                ? BinName::where('trash_bin_id', $bin->id)->where('user_id', Auth::id())->latest('id')->first()
                : null,
        ];
    }
}; ?>

<div class="mt-6 rounded-2xl bg-radar-card p-5 shadow"
     @if ($mySubmission?->status === \App\Models\BinName::STATUS_PENDING) wire:poll.5s @endif>
    @if ($current)
        <p class="text-xs font-semibold text-amber-300">🏴 {{ __('佔領中') }}</p>
        <p class="mt-1 break-all text-2xl font-black text-white">「{{ $current->name }}」</p>
        <p class="mt-1 text-xs text-slate-400">{{ __('由 :owner 命名', ['owner' => $current->ownerLabel()]) }}</p>
    @else
        <p class="text-sm font-semibold text-slate-200">🏳️ {{ __('這個桶還沒有名字') }}</p>
        <p class="mt-1 text-xs text-slate-400">{{ __('巡最多次的人可以幫它取名，名字會掛在地圖上') }}</p>
    @endif

    <h2 class="mt-5 text-sm font-bold text-slate-200">{{ __('佔領榜') }}</h2>
    @if ($standings->isEmpty())
        <p class="mt-2 text-sm text-slate-400">{{ __('還沒有人巡過這個桶，成為第一個！') }}</p>
    @else
        <ol class="mt-2 space-y-1.5">
            @foreach ($standings as $i => $row)
                <li class="flex items-center gap-3 rounded-lg px-3 py-2 {{ $i === 0 ? 'bg-amber-500/10 ring-1 ring-amber-500/30' : 'bg-white/5' }}">
                    <span class="w-6 text-center">{{ ['🥇', '🥈', '🥉'][$i] }}</span>
                    <span class="flex-1 truncate text-sm text-slate-200">
                        {{ $row['user']->is_private ? __('匿名巡桶員') : $row['user']->name }}
                        @if ($row['user']->id === auth()->id()) <span class="text-xs text-amber-300">（{{ __('你') }}）</span> @endif
                    </span>
                    <span class="text-sm font-bold text-amber-400">{{ __(':count 次', ['count' => $row['count']]) }}</span>
                </li>
            @endforeach
        </ol>
    @endif

    <div class="mt-4 border-t border-white/10 pt-4">
        @guest
            <p class="text-sm text-slate-300">{{ __('登入後回報這個桶，就能累積佔領次數') }}</p>
        @else
            @if ($mySubmission?->status === \App\Models\BinName::STATUS_PENDING)
                <p class="text-sm text-sky-300">⏳ {{ __('「:name」審核中，通常幾秒內完成', ['name' => $mySubmission->name]) }}</p>
            @elseif ($mySubmission?->status === \App\Models\BinName::STATUS_REJECTED)
                <p class="text-sm text-rose-300">
                    {{ __('「:name」沒有通過審核', ['name' => $mySubmission->name]) }}{{ $mySubmission->moderation_note ? '：'.$mySubmission->moderation_note : '' }}
                </p>
            @endif

            @if ($isLeader)
                <p class="mt-2 text-sm font-semibold text-amber-300">👑 {{ __('你是第一名！幫這個桶取個名字吧') }}</p>
                <form wire:submit="submit" class="mt-2 flex gap-2">
                    <input type="text" wire:model="name" maxlength="{{ config('petmin.bin_naming.name_max_length') }}"
                           placeholder="{{ __('例如：柴柴的秘密基地') }}"
                           class="min-w-0 flex-1 rounded-lg border border-white/15 bg-black/20 px-3 py-2 text-sm text-white placeholder:text-slate-500">
                    <button type="submit" wire:loading.attr="disabled"
                            class="shrink-0 rounded-lg bg-amber-400 px-4 py-2 text-sm font-bold text-amber-950 hover:bg-amber-300">{{ __('命名') }}</button>
                </form>
                @error('name') <p class="mt-1 text-xs text-rose-300">{{ $message }}</p> @enderror
            @elseif ($myCount < $min)
                <p class="text-sm text-slate-300">{{ __('你目前 :count 次有效回報，再 :left 次就有機會命名', ['count' => $myCount, 'left' => $min - $myCount]) }}</p>
            @else
                <p class="text-sm text-slate-300">{{ __('你目前 :count 次有效回報，超過第一名就能命名', ['count' => $myCount]) }}</p>
            @endif
        @endguest
    </div>

    <details class="mt-4 text-xs text-slate-400">
        <summary class="cursor-pointer">{{ __('怎麼算次數？') }}</summary>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li>{{ __('只算最近 :days 天內、審核通過的回報', ['days' => config('petmin.bin_naming.window_days')]) }}</li>
            <li>{{ __('同一個人 :hours 小時內只算一次', ['hours' => config('petmin.bin_naming.cooldown_hours')]) }}</li>
            <li>{{ __('同一個桶 :hours 小時內最多算 :users 個人（交叉驗證，揪團灌也灌不動）', ['hours' => config('petmin.bin_naming.cooldown_hours'), 'users' => config('petmin.bin_naming.max_users_per_window')]) }}</li>
            <li>{{ __('滿 :min 次而且排第一就能命名；同分時先達到的人優先', ['min' => $min]) }}</li>
            <li>{{ __('被超越時舊名字會留著，直到新的第一名命名') }}</li>
            <li>{{ __('名字會經過審核，不雅、廣告、真實人物、政治相關的名字不會公開') }}</li>
        </ul>
    </details>
</div>
