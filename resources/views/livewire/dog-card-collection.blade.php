<?php

use App\Models\DogCard;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** 垃圾桶圖鑑：列出所有卡片種類，已收集的顯示圖與張數，沒收集到的顯示剪影，引導再去巡桶。 */
new class extends Component
{
    public function with(): array
    {
        $owned = DogCard::where('user_id', Auth::id())
            ->selectRaw('card_key, count(*) as n')
            ->groupBy('card_key')
            ->pluck('n', 'card_key');

        $cards = collect(DogCard::definitions())->map(fn (array $def, string $key) => [
            ...$def,
            'key' => $key,
            'count' => (int) ($owned[$key] ?? 0),
        ])->values();

        // 還沒設計的卡用剪影補到 dex_slots 格，輪流換輪廓，看起來是各種不同的卡
        $silhouettes = config('petmin.gacha.placeholder_images', []);
        $slots = max((int) config('petmin.gacha.dex_slots'), $cards->count());
        $placeholders = collect(range(1, $slots - $cards->count()))
            ->filter(fn () => $slots > $cards->count())
            ->map(fn (int $i) => ['key' => "placeholder-{$i}", 'title' => '', 'image' => $silhouettes ? $silhouettes[($i - 1) % count($silhouettes)] : 'card-back', 'count' => 0]);

        return [
            'cards' => $cards->concat($placeholders)->values(),
            'collected' => $cards->where('count', '>', 0)->count(),
        ];
    }
}; ?>

<section id="dog-cards" class="mx-auto max-w-2xl px-4 pt-10">
    <div class="flex items-baseline justify-between gap-3">
        <h2 class="text-base font-bold text-slate-200">🐾 {{ __('垃圾桶圖鑑') }}</h2>
        <span class="text-sm text-slate-400">{{ __('已收集 :n / :total', ['n' => $collected, 'total' => $cards->count()]) }}</span>
    </div>
    <p class="mt-1 text-sm text-slate-400">{{ __('每次回報桶況都會抽一段扭蛋劇情，收集不一樣的卡片。去巡桶就有機會抽到新卡！') }}</p>

    <div class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4 sm:gap-3">
        @foreach ($cards as $card)
            <div class="rounded-2xl bg-radar-card p-3 text-center shadow">
                <div class="flex aspect-square items-center justify-center rounded-xl {{ $card['count'] ? 'bg-amber-100' : 'bg-slate-800' }}">
                    <img src="/gacha/{{ $card['image'] }}.webp" alt="{{ $card['count'] ? $card['title'] : __('尚未收集') }}"
                         class="h-4/5 w-auto object-contain {{ $card['count'] ? '' : 'opacity-40 brightness-0' }}">
                </div>
                <div class="mt-2 text-sm font-bold {{ $card['count'] ? 'text-slate-100' : 'text-slate-500' }}">
                    {{ $card['count'] ? $card['title'] : '？？？' }}
                </div>
                @if ($card['count'])
                    <div class="text-xs text-slate-400">{{ __('收集 :n 張', ['n' => $card['count']]) }}</div>
                @endif
            </div>
        @endforeach
    </div>
</section>
