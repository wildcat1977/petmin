<?php

use App\Jobs\ModerateBinName;
use App\Models\BinName;
use App\Models\TrashBin;
use App\Services\BinNameModerationService;
use App\Services\BinOccupationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * 打卡頁的佔領榜（規則見 config petmin.bin_naming）：次數夠、而且目前的佔領過了保護期，
 * 一進頁面就跳「恭喜你佔領這個桶」要他命名；次數夠但還在保護期，告訴他幾點再來。
 * 名字當場過格式與黑名單，再丟 ModerateBinName 給 AI 審；通過前地圖上照掛舊名，沒有空白期。
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

        $bin = TrashBin::with('currentName')->findOrFail($this->binId);
        $status = $occupation->status($bin, Auth::user());
        if ($status['state'] !== 'eligible') {
            $this->addError('name', match ($status['state']) {
                'occupier' => __('你已經佔領這個桶了'),
                'waiting' => __('目前的佔領還在保護期，:time 之後再來', ['time' => $status['available_at']->format('n/j H:i')]),
                default => __('還差 :left 次才能佔領', ['left' => $status['left']]),
            });

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
        $current = $bin->currentName;
        $mySubmission = Auth::check()
            ? BinName::where('trash_bin_id', $bin->id)->where('user_id', Auth::id())->latest('id')->first()
            : null;
        $status = Auth::check() ? $occupation->status($bin, Auth::user()) : null;

        return [
            'current' => $current,
            // 被佔領的桶只看佔領之後的次數，跟命名權的算法一致
            'standings' => $occupation->standings($bin, $current ? $occupation->occupiedSince($current) : null)->take(3),
            'status' => $status,
            // 自己最近一次送審：審核中要顯示、被退要讓本人知道原因（不給別人看）
            'mySubmission' => $mySubmission,
            'canName' => ($status['state'] ?? null) === 'eligible' && $mySubmission?->status !== BinName::STATUS_PENDING,
        ];
    }
}; ?>

@php $min = config('petmin.bin_naming.min_reports'); $protect = config('petmin.bin_naming.protect_hours'); @endphp
<div class="mt-6 rounded-2xl bg-radar-card p-5 shadow" x-data="{ claim: @js($canName) }"
     @if ($mySubmission?->status === \App\Models\BinName::STATUS_PENDING) wire:poll.5s @endif>
    @if ($current)
        <p class="text-xs font-semibold text-amber-300">🏴 {{ __('佔領中') }}</p>
        <p class="mt-1 break-all text-2xl font-black text-white">「{{ $current->name }}」</p>
        <p class="mt-1 text-xs text-slate-400">{{ __('由 :owner 命名', ['owner' => $current->ownerLabel()]) }}</p>
    @else
        <p class="text-sm font-semibold text-slate-200">🏳️ {{ __('這個桶還沒有名字') }}</p>
        <p class="mt-1 text-xs text-slate-400">{{ __('巡滿 :min 次就能佔領它、幫它取名，名字會掛在地圖上', ['min' => $min]) }}</p>
    @endif

    <h2 class="mt-5 text-sm font-bold text-slate-200">{{ __('佔領榜') }}</h2>
    @if ($current)
        <p class="text-xs text-slate-400">{{ __('從目前的佔領開始重新計算') }}</p>
    @endif
    @if ($standings->isEmpty())
        <p class="mt-2 text-sm text-slate-400">{{ $current ? __('還沒有人來挑戰，成為第一個！') : __('還沒有人巡過這個桶，成為第一個！') }}</p>
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
            @elseif ($mySubmission?->status === \App\Models\BinName::STATUS_REJECTED && $status['state'] !== 'occupier')
                <p class="text-sm text-rose-300">
                    {{ __('「:name」沒有通過審核', ['name' => $mySubmission->name]) }}{{ $mySubmission->moderation_note ? '：'.$mySubmission->moderation_note : '' }}
                </p>
            @endif

            @switch($status['state'])
                @case('occupier')
                    <p class="text-sm font-semibold text-amber-300">🏴 {{ __('你正在佔領這個桶') }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ __('別人要從你佔領後重新巡滿 :min 次，而且你佔領滿 :hours 小時，才搶得走', ['min' => $min, 'hours' => $protect]) }}</p>
                    @break
                @case('eligible')
                    @if ($canName)
                        <p class="text-sm font-semibold text-amber-300">🎉 {{ __('你可以佔領這個桶了！') }}</p>
                        <button type="button" x-on:click="claim = true"
                                class="mt-2 rounded-lg bg-amber-400 px-4 py-2 text-sm font-bold text-amber-950 hover:bg-amber-300">{{ __('幫它命名') }}</button>
                    @endif
                    @break
                @case('waiting')
                    <p class="text-sm font-semibold text-amber-300">⏰ {{ __('你已經巡滿 :min 次了！', ['min' => $min]) }}</p>
                    <p class="mt-1 text-sm text-slate-300">
                        {{ __('目前的佔領還在保護期，還要等 :wait，:time 之後再來就能佔領', [
                            'wait' => $status['available_at']->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2),
                            'time' => $status['available_at']->format('n/j H:i'),
                        ]) }}
                    </p>
                    @break
                @default
                    <p class="text-sm text-slate-300">
                        {{ $current
                            ? __('你在目前的佔領之後巡了 :count 次，再 :left 次就能搶下這個桶', ['count' => $status['count'], 'left' => $status['left']])
                            : __('你目前 :count 次有效回報，再 :left 次就能佔領', ['count' => $status['count'], 'left' => $status['left']]) }}
                    </p>
            @endswitch
        @endguest
    </div>

    <details class="mt-4 text-xs text-slate-400">
        <summary class="cursor-pointer">{{ __('怎麼算次數？') }}</summary>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li>{{ __('只算最近 :days 天內、審核通過的回報', ['days' => config('petmin.bin_naming.window_days')]) }}</li>
            <li>{{ __('同一個人 :hours 小時內只算一次', ['hours' => config('petmin.bin_naming.cooldown_hours')]) }}</li>
            <li>{{ __('同一個桶 :hours 小時內最多算 :users 個人（交叉驗證，揪團灌也灌不動）', ['hours' => config('petmin.bin_naming.cooldown_hours'), 'users' => config('petmin.bin_naming.max_users_per_window')]) }}</li>
            <li>{{ __('沒人佔領的桶，巡滿 :min 次就能佔領命名，先命名先贏', ['min' => $min]) }}</li>
            <li>{{ __('已被佔領的桶，要從佔領之後重新巡滿 :min 次，而且佔領滿 :hours 小時才搶得走', ['min' => $min, 'hours' => $protect]) }}</li>
            <li>{{ __('新名字通過審核之前，地圖上照掛舊名字') }}</li>
            <li>{{ __('名字會經過審核，不雅、廣告、真實人物、政治相關的名字不會公開') }}</li>
        </ul>
    </details>

    {{-- 恭喜佔領：一進頁面就跳出來要他命名，關掉後可以從上面的按鈕再打開 --}}
    @if ($canName)
        <div x-show="claim" x-cloak x-transition.opacity
             class="fixed inset-0 z-[1800] flex items-center justify-center bg-black/70 p-4" x-on:keydown.escape.window="claim = false">
            <div class="w-full max-w-sm rounded-2xl bg-radar-card p-6 text-center shadow-xl" x-on:click.outside="claim = false">
                <p class="text-4xl">🎉</p>
                <h3 class="mt-2 text-xl font-black text-white">{{ __('恭喜你佔領這個桶！') }}</h3>
                <p class="mt-1 text-sm text-slate-300">
                    {{ $current ? __('「:name」的佔領結束了，幫它取個新名字吧', ['name' => $current->name]) : __('幫它取個名字，名字會掛在地圖上') }}
                </p>
                <form wire:submit="submit" class="mt-4 space-y-2">
                    <input type="text" wire:model="name" maxlength="{{ config('petmin.bin_naming.name_max_length') }}"
                           placeholder="{{ __('例如：柴柴的秘密基地') }}"
                           class="w-full rounded-lg border border-white/15 bg-black/20 px-3 py-2 text-sm text-white placeholder:text-slate-500">
                    @error('name') <p class="text-xs text-rose-300">{{ $message }}</p> @enderror
                    <button type="submit" wire:loading.attr="disabled"
                            class="w-full rounded-lg bg-amber-400 px-4 py-2 text-sm font-bold text-amber-950 hover:bg-amber-300">{{ __('命名') }}</button>
                </form>
                <button type="button" x-on:click="claim = false" class="mt-3 text-xs text-slate-400 underline">{{ __('等一下再取') }}</button>
            </div>
        </div>
    @endif
</div>
