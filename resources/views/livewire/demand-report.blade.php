<?php

use App\Models\DemandCell;
use App\Services\DemandScoreService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 缺桶報表：行政區排行（哪一區最缺）＋候選地點明細表（300m 去重後的全部候選，可篩選排序、CSV 照篩選匯出）。
 * 篩選與排序寫進網址，報表連結可以直接分享給環保局。
 */
new class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $district = '';

    #[Url(except: 'score')]
    public string $sort = 'score';

    #[Url(except: 'desc')]
    public string $dir = 'desc';

    /** 點欄位：第一次由大到小，再點一次反轉；不在白名單的欄位回到預設 */
    public function sortBy(string $column): void
    {
        if (! in_array($column, DemandCell::SORTABLE, true)) {
            [$this->sort, $this->dir] = ['score', 'desc'];

            return;
        }
        $this->dir = $this->sort === $column && $this->dir === 'desc' ? 'asc' : 'desc';
        $this->sort = $column;
        $this->resetPage();
    }

    /** 行政區排行點區名＝篩選明細；再點一次取消 */
    public function filterDistrict(string $district): void
    {
        $this->district = $this->district === $district ? '' : $district;
        $this->resetPage();
    }

    public function updatedDistrict(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'ranking' => app(DemandScoreService::class)->districtRanking(),
            'candidates' => DemandCell::query()->candidateList($this->district ?: null, $this->sort, $this->dir)->paginate(25),
            'labels' => DemandCell::BREAKDOWN_LABELS,
            'hotspotMin' => config('petmin.demand.hotspot_min_score'),
        ];
    }
}; ?>

<div>
    {{-- 行政區缺桶排行 --}}
    <section class="mt-10">
        <h2 class="text-base font-bold text-slate-100">🏙️ {{ __('行政區缺桶排行') }}</h2>
        <p class="mt-1 text-xs text-slate-500">{{ __('缺桶熱點＝需求分數 ≥ :min 的候選地點；有袋沒桶＝狗便袋箱 :m 公尺內沒有清潔箱。點區名篩選下方明細。', ['min' => $hotspotMin, 'm' => config('petmin.bag_box_gap_meters')]) }}</p>
        <div class="mt-3 overflow-x-auto rounded-xl border border-white/10">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-white/5 text-left text-xs text-slate-400">
                    <tr>
                        <th class="px-3 py-2">{{ __('行政區') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('缺桶熱點') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('有袋沒桶') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('清潔箱') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('狗便袋箱') }}</th>
                        <th class="px-3 py-2">{{ __('最缺的地點') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @foreach ($ranking as $row)
                        <tr wire:key="district-{{ $row['district'] }}" class="{{ $district === $row['district'] ? 'bg-orange-500/10' : '' }}">
                            <td class="px-3 py-2">
                                <button type="button" wire:click="filterDistrict('{{ $row['district'] }}')" class="font-semibold text-cyan-300 hover:underline">{{ $row['district'] }}</button>
                            </td>
                            <td class="px-3 py-2 text-right font-bold {{ $row['hotspots'] > 0 ? 'text-orange-300' : 'text-slate-500' }}">{{ $row['hotspots'] }}</td>
                            <td class="px-3 py-2 text-right text-slate-300">
                                {{ $row['bag_gaps'] }}/{{ $row['bag_boxes'] }}
                                @if ($row['bag_gap_pct'] !== null)<span class="text-xs text-slate-500">（{{ $row['bag_gap_pct'] }}%）</span>@endif
                            </td>
                            <td class="px-3 py-2 text-right text-slate-300">{{ $row['clean_boxes'] }}</td>
                            <td class="px-3 py-2 text-right text-slate-300">{{ $row['bag_boxes'] }}</td>
                            <td class="max-w-[220px] truncate px-3 py-2 text-xs text-slate-400">
                                @if ($row['top'])
                                    {{ $row['top']->explanation()['title'] }} <span class="text-orange-300">{{ $row['top']->score }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- 候選地點明細 --}}
    <section class="mt-10">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-slate-100">🔥 {{ __('候選地點明細') }} <span class="ml-1 rounded-full bg-orange-500/15 px-2 py-0.5 text-xs font-semibold text-orange-300">{{ $candidates->total() }}</span></h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('同一熱點 300 公尺內只列分數最高的一格。點欄位標題排序，點列展開分數明細。') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <select wire:model.live="district" class="rounded-lg border border-white/10 bg-slate-900 px-2 py-1.5 text-sm text-slate-200">
                    <option value="">{{ __('全部行政區') }}</option>
                    @foreach ($ranking as $row)
                        <option value="{{ $row['district'] }}">{{ $row['district'] }}</option>
                    @endforeach
                </select>
                <a href="{{ route('candidates.export', array_filter(['district' => $district, 'sort' => $sort !== 'score' ? $sort : null, 'dir' => $dir !== 'desc' ? $dir : null])) }}"
                   class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-emerald-500">{{ __('↓ 匯出 CSV') }}</a>
            </div>
        </div>

        @if ($candidates->isEmpty())
            <p class="mt-3 rounded-xl bg-white/5 p-6 text-center text-sm text-slate-500">{{ __('尚未計算。請執行 php artisan demand:recompute。') }}</p>
        @else
            @php
                $columns = ['score' => __('需求分數'), 'nearest_bin_m' => __('離桶'), 'dog_score' => __('遛狗'), 'mrt_score' => __('捷運'), 'bus_score' => __('公車'), 'report_score' => __('回報')];
            @endphp
            <div class="mt-3 overflow-x-auto rounded-xl border border-white/10">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="bg-white/5 text-left text-xs text-slate-400">
                        <tr>
                            <th class="w-10 px-3 py-2">#</th>
                            <th class="px-3 py-2">{{ __('地點') }}</th>
                            <th class="px-3 py-2">{{ __('行政區') }}</th>
                            @foreach ($columns as $key => $label)
                                <th class="px-3 py-2 text-right">
                                    <button type="button" wire:click="sortBy('{{ $key }}')" class="hover:text-slate-200 {{ $sort === $key ? 'font-bold text-orange-300' : '' }}">
                                        {{ $label }}@if ($sort === $key){{ $dir === 'desc' ? ' ▼' : ' ▲' }}@endif
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    @foreach ($candidates as $cell)
                        <tbody x-data="{ open: false }" wire:key="cell-{{ $cell->id }}" class="border-t border-white/5">
                            <tr class="cursor-pointer hover:bg-white/5" @click="open = ! open">
                                <td class="px-3 py-2 text-xs font-bold {{ $cell->candidate_rank <= 3 ? 'text-amber-400' : 'text-slate-500' }}">{{ $cell->candidate_rank }}</td>
                                <td class="max-w-[240px] truncate px-3 py-2 text-slate-200">{{ $cell->explanation()['title'] }}</td>
                                <td class="px-3 py-2 text-xs text-slate-400">{{ $cell->district }}</td>
                                <td class="px-3 py-2 text-right font-bold text-orange-300">{{ number_format($cell->score, 1) }}</td>
                                <td class="px-3 py-2 text-right text-slate-300">{{ $cell->nearest_bin_m !== null ? ($cell->nearest_bin_m >= 1000 ? number_format($cell->nearest_bin_m / 1000, 1).'km' : $cell->nearest_bin_m.'m') : '—' }}</td>
                                @foreach (['dog_score', 'mrt_score', 'bus_score', 'report_score'] as $key)
                                    <td class="px-3 py-2 text-right {{ $cell->{$key} > 0 ? 'text-slate-300' : 'text-slate-600' }}">{{ $cell->{$key} > 0 ? number_format($cell->{$key}, 1) : '—' }}</td>
                                @endforeach
                            </tr>
                            <tr x-show="open" x-cloak>
                                <td colspan="9" class="bg-black/20 px-4 py-3 text-xs text-slate-400">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-slate-500">{{ __('網格 :g（中心 :lat, :lng）', ['g' => $cell->cell, 'lat' => number_format($cell->latitude, 5), 'lng' => number_format($cell->longitude, 5)]) }}</span>
                                        <a href="{{ route('home') }}#map={{ $cell->latitude }},{{ $cell->longitude }},16" class="text-cyan-300 hover:underline">{{ __('看地圖') }}</a>
                                    </div>
                                    <ul class="mt-2 space-y-1">
                                        @foreach ($cell->explanation()['items'] as $item)
                                            <li class="flex items-center justify-between gap-2">
                                                <span>{{ $item['label'] }}{{ $item['key'] === 'bin_gap' ? '' : ' × '.$item['count'] }}@if ($item['note'])<span class="text-slate-600">（{{ $item['note'] }}）</span>@endif</span>
                                                <span class="{{ $item['score'] < 0 ? 'text-rose-300' : 'text-emerald-300' }}">{{ $item['score'] > 0 ? '+' : '' }}{{ round($item['score'], 1) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>

            @if ($candidates->hasPages())
                <div class="mt-3 flex items-center justify-between text-sm text-slate-400">
                    <button type="button" wire:click="previousPage" @disabled($candidates->onFirstPage()) class="rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5 disabled:opacity-30">{{ __('上一頁') }}</button>
                    <span>{{ $candidates->currentPage() }} / {{ $candidates->lastPage() }}</span>
                    <button type="button" wire:click="nextPage" @disabled(! $candidates->hasMorePages()) class="rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5 disabled:opacity-30">{{ __('下一頁') }}</button>
                </div>
            @endif
        @endif
    </section>
</div>
