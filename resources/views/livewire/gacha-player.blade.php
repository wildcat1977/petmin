<?php

use App\Models\DogCard;
use App\Models\Report;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * 扭蛋：聽回報表單送出後發的 report-submitted，替這位使用者最新那筆回報抽一張卡存進圖鑑，
 * 再發 gacha-play 給頁面上的劇情播放器（partials/gacha-stage）。不改回報表單本身。
 * 一筆回報只抽一次（dog_cards.report_id unique），重送事件不會重複抽。
 */
new class extends Component
{
    #[On('report-submitted')]
    public function award(): void
    {
        if (! Auth::check()) {
            return;
        }

        $report = Report::where('user_id', Auth::id())->latest('id')->first();
        $cards = DogCard::definitions();
        if (! $report || ! $cards || DogCard::where('report_id', $report->id)->exists()) {
            return;
        }

        $key = Arr::random(array_keys($cards));
        DogCard::create(['user_id' => Auth::id(), 'report_id' => $report->id, 'card_key' => $key]);

        $this->dispatch('gacha-play',
            story: $cards[$key]['story'],
            card: ['key' => $key, ...$cards[$key]],
            collected: DogCard::where('user_id', Auth::id())->distinct()->count('card_key'),
            total: count($cards),
        );
    }
}; ?>

<div hidden></div>
