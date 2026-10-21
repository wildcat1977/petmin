<?php

namespace App\Services;

use App\Models\DemandCell;
use App\Models\DemandProxy;
use App\Models\FarSignal;
use App\Models\Report;
use App\Models\TrashBin;
use App\Support\DemandGrid;
use App\Support\Geo;
use App\Support\TaipeiBoundary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 需求分數（純程式，無 AI）：以 DemandGrid 方格（預設 150m）為單位，每格看「格中心半徑內」的訊號——
 * 不看訊號落在哪一格，隔壁馬路的站牌、桶照樣算（舊版 geohash6 分桶會因格線不同而漏算）。
 *
 *   score = (Σ 訊號 × 距離衰減) × (1 + 缺桶加成) − Σ 半徑內現存清潔箱 × penalty × 距離衰減
 *   dumping_score = Σ 家戶垃圾亂丟（已核准）回報 × 佐證倍率 × 距離衰減（撤除風險）
 *
 * - 一般訊號（公車、袋箱、狗活動區、公園、陳情、回報）：radius_m 內線性衰減
 * - 捷運出口：甜甜圈（config petmin.demand.mrt_exit）——站內垃圾桶在閘門旁，需求出現在出站往外走的路上
 * - 缺桶加成：格中心到最近現存清潔箱的距離（config petmin.demand.bin_gap）；乘在訊號上，山區弱訊號不會被放大成熱區
 * 每格保留 breakdown 作為證據明細，/candidates 與地圖點擊明細直接顯示。
 */
class DemandScoreService
{
    /** 空間索引的桶大小（公尺）：要不小於最大的影響半徑，查詢只需掃鄰近 3×3 桶 */
    private const BUCKET_M = 500;

    /** @return int 重算後的格數 */
    public function recompute(): int
    {
        $grid = DemandGrid::fromConfig();
        $w = config('petmin.demand.weights');
        $radius = (float) config('petmin.demand.radius_m');
        $donut = config('petmin.demand.mrt_exit');

        [$signals, $dumping] = $this->collectSignals($grid, $w);
        $cleanBoxes = TrashBin::query()->active()->kind(TrashBin::KIND_CLEAN_BOX)
            ->get(['box_count', 'address', 'latitude', 'longitude'])
            ->map(fn (TrashBin $b) => [...$grid->toMeters((float) $b->latitude, (float) $b->longitude), max(1, (int) $b->box_count), $b->address])
            ->all();

        $signalIndex = $this->index($signals);
        $dumpingIndex = $this->index($dumping);
        $binIndex = $this->index($cleanBoxes);

        $now = now();
        $rows = [];
        foreach ($this->touchedCells($grid, [...$signals, ...$dumping], $radius, (float) $donut['outer_m']) as [$ix, $iy]) {
            $center = $grid->center($ix, $iy);
            // 市界外（新北）district 為 null：街頭已全面撤除行人清潔箱，地圖照畫；但只有捷運資料，不排名次
            $district = TaipeiBoundary::districtAt($center['lat'], $center['lng']);
            [$cx, $cy] = $grid->toMeters($center['lat'], $center['lng']);

            $breakdown = [];
            foreach ($this->near($signalIndex, $cx, $cy) as [$point, $d]) {
                [, , $key, $value, $label, $isDonut] = $point;
                $k = $isDonut ? $this->donut($d, (float) $donut['inner_m'], (float) $donut['outer_m']) : $this->linear($d, $radius);
                if ($k > 0) {
                    $this->addPart($breakdown, $key, $value * $k, $label);
                }
            }
            $dumpingScore = 0.0;
            foreach ($this->near($dumpingIndex, $cx, $cy) as [$point, $d]) {
                $k = $this->linear($d, $radius);
                if ($k > 0) {
                    $dumpingScore += $point[3] * $k;
                    $this->addPart($breakdown, 'report_dumping', $point[3] * $k, $point[4]);
                }
            }

            $this->applyCaps($breakdown);
            $signal = collect($breakdown)->except('report_dumping')->sum('score');
            if (round($signal, 2) <= 0 && round($dumpingScore, 2) <= 0) { // 甜甜圈內緣等浮點殘值不算訊號
                continue;
            }

            $nearest = $this->nearestDistance($binIndex, $cx, $cy);
            $score = $signal + $this->binGapBonus($breakdown, $signal, $nearest);
            foreach ($this->near($binIndex, $cx, $cy) as [$bin, $d]) {
                $k = $this->linear($d, $radius);
                if ($k > 0) {
                    $penalty = $w['existing_bin_penalty'] * $bin[2] * $k;
                    $score -= $penalty;
                    $this->addPart($breakdown, 'existing_bin', -$penalty, $bin[3]);
                }
            }

            $rows[] = [
                'cell' => $grid->id($ix, $iy),
                'ix' => $ix,
                'iy' => $iy,
                'latitude' => round($center['lat'], 7),
                'longitude' => round($center['lng'], 7),
                'score' => round(max(0.0, $score), 2),
                'dumping_score' => round($dumpingScore, 2),
                'breakdown' => json_encode($this->finalizeBreakdown($breakdown), JSON_UNESCAPED_UNICODE),
                'computed_at' => $now,
                // 報表用：行政區與各來源分數拆欄，篩選排序不必解 JSON
                'district' => $district,
                'nearest_bin_m' => is_finite($nearest) ? (int) round($nearest) : null,
                'dog_score' => round(($breakdown['dog_bag_box']['score'] ?? 0) + ($breakdown[DemandProxy::KIND_DOG_PARK]['score'] ?? 0), 2),
                'mrt_score' => round($breakdown['mrt']['score'] ?? 0, 2),
                'bus_score' => round($breakdown[DemandProxy::KIND_BUS_STOP]['score'] ?? 0, 2),
                'report_score' => round(($breakdown['report_need_bin']['score'] ?? 0) + ($breakdown['report_full']['score'] ?? 0), 2),
                'candidate_rank' => null,
                '_x' => $cx,
                '_y' => $cy,
            ];
        }

        $rows = $this->rankCandidates($rows);

        DB::transaction(function () use ($rows) {
            DemandCell::query()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DemandCell::insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * 訊號點 [x, y, key, value, label, isDonut]；家戶垃圾回報另成一組（撤除風險，不計入需求）。
     *
     * @return array{0: list<array>, 1: list<array>}
     */
    private function collectSignals(DemandGrid $grid, array $w): array
    {
        $signals = [];
        $point = fn (float $lat, float $lng, string $key, float $value, ?string $label, bool $isDonut = false) => [...$grid->toMeters($lat, $lng), $key, $value, $label, $isDonut];

        // KIND_MRT 站點列只是出口匯入的權重來源，計分只看出口
        DemandProxy::query()
            ->whereIn('kind', [DemandProxy::KIND_MRT_EXIT, DemandProxy::KIND_BUS_STOP, DemandProxy::KIND_DOG_PARK, DemandProxy::KIND_PARK, DemandProxy::KIND_PETITION])
            ->select(['kind', 'name', 'latitude', 'longitude', 'weight', 'meta'])
            ->lazy()
            ->each(function (DemandProxy $p) use (&$signals, $point, $w) {
                $isExit = $p->kind === DemandProxy::KIND_MRT_EXIT;
                $key = $isExit ? 'mrt' : $p->kind;
                $signals[] = $point((float) $p->latitude, (float) $p->longitude, $key, $w[$key] * (float) $p->weight,
                    $isExit ? ($p->meta['station'] ?? $p->name) : $p->name, $isExit);
            });

        // 狗便袋箱＝遛狗族聚集的證據（提案公式「狗便袋箱 ×2」）。同一公園常裝一整排袋箱，
        // dog_bag_box_site_m 內併成「一個遛狗地點」只算一次，否則袋箱裝得多的公園會被灌高分
        $boxes = TrashBin::query()->active()->kind(TrashBin::KIND_DOG_BAG_BOX)->orderBy('id')
            ->get(['address', 'latitude', 'longitude'])
            ->map(fn (TrashBin $b) => [...$grid->toMeters((float) $b->latitude, (float) $b->longitude), $b->address])
            ->all();
        foreach ($this->groupIntoSites($boxes, (float) config('petmin.demand.dog_bag_box_site_m')) as $site) {
            $label = count($site) > 1 ? "{$site[0][2]} 等 ".count($site).' 個袋箱' : $site[0][2];
            $signals[] = [array_sum(array_column($site, 0)) / count($site), array_sum(array_column($site, 1)) / count($site),
                TrashBin::KIND_DOG_BAG_BOX, $w['dog_bag_box'], $label, false];
        }

        $dumping = [];
        Report::query()
            ->where('status', Report::STATUS_APPROVED)
            ->whereNull('resolved_at')
            ->whereIn('category', [Report::CATEGORY_NEED_BIN, Report::CATEGORY_FULL, Report::CATEGORY_DUMPING])
            ->select(['category', 'latitude', 'longitude', 'verification_level', 'address'])
            ->lazy()
            ->each(function (Report $r) use (&$signals, &$dumping, $point, $w) {
                $mult = $r->verification_level === Report::VERIFICATION_UNVERIFIED ? 1.0 : $w['corroborated_multiplier'];
                if ($r->category === Report::CATEGORY_DUMPING) {
                    $dumping[] = $point((float) $r->latitude, (float) $r->longitude, 'report_dumping', $mult, $r->address);

                    return;
                }
                $base = $r->category === Report::CATEGORY_NEED_BIN ? $w['report_need_bin'] : $w['report_full'];
                $signals[] = $point((float) $r->latitude, (float) $r->longitude, 'report_'.$r->category, $base * $mult, $r->address);
            });

        // 「好遠！」：寫入時已是格中心、已依 IP／格／日去重，這裡每筆算一個訊號，靠 caps 封頂
        FarSignal::query()
            ->select(['latitude', 'longitude', 'district'])
            ->lazy()
            ->each(function (FarSignal $f) use (&$signals, $point, $w) {
                $signals[] = $point((float) $f->latitude, (float) $f->longitude, 'far_signal', $w['far_signal'] ?? 1.0, $f->district);
            });

        return [$signals, $dumping];
    }

    /**
     * 依序以尚未歸組的點為中心，把 $radius 內未歸組的點併成一組（輸入順序固定，結果穩定）。
     *
     * @param  list<array{0: float, 1: float, 2: ?string}>  $points
     * @return list<list<array{0: float, 1: float, 2: ?string}>>
     */
    private function groupIntoSites(array $points, float $radius): array
    {
        $sites = [];
        $assigned = [];
        foreach ($points as $i => $seed) {
            if (isset($assigned[$i])) {
                continue;
            }
            $site = [];
            foreach ($points as $j => $p) {
                if (! isset($assigned[$j]) && hypot($p[0] - $seed[0], $p[1] - $seed[1]) <= $radius) {
                    $assigned[$j] = true;
                    $site[] = $p;
                }
            }
            $sites[] = $site;
        }

        return $sites;
    }

    /**
     * 每個訊號點影響半徑內的格子（格中心在半徑內）。
     *
     * @return list<array{0: int, 1: int}>
     */
    private function touchedCells(DemandGrid $grid, array $points, float $radius, float $donutOuter): array
    {
        $cells = [];
        foreach ($points as [$x, $y, , , , $isDonut]) {
            $reach = $isDonut ? $donutOuter : $radius;
            for ($ix = (int) floor(($x - $reach) / $grid->size); $ix <= (int) floor(($x + $reach) / $grid->size); $ix++) {
                for ($iy = (int) floor(($y - $reach) / $grid->size); $iy <= (int) floor(($y + $reach) / $grid->size); $iy++) {
                    if (hypot(($ix + 0.5) * $grid->size - $x, ($iy + 0.5) * $grid->size - $y) <= $reach) {
                        $cells["{$ix}:{$iy}"] = [$ix, $iy];
                    }
                }
            }
        }

        return array_values($cells);
    }

    /** 缺桶加成寫進 breakdown 並回傳加成分數 */
    private function binGapBonus(array &$breakdown, float $signal, float $nearest): float
    {
        $gap = config('petmin.demand.bin_gap');
        if ($gap['max_multiplier'] <= 0 || $signal <= 0) {
            return 0.0;
        }
        $ratio = min(1.0, max(0.0, ($nearest - $gap['start_m']) / ($gap['full_m'] - $gap['start_m'])));
        if ($ratio <= 0) {
            return 0.0;
        }
        $bonus = $signal * $ratio * $gap['max_multiplier'];
        $breakdown['bin_gap'] = ['count' => 1, 'score' => $bonus, 'contrib' => [], 'nearest_m' => is_finite($nearest) ? (int) round($nearest) : null];

        return $bonus;
    }

    private function addPart(array &$breakdown, string $key, float $value, ?string $label): void
    {
        $breakdown[$key] ??= ['count' => 0, 'score' => 0.0, 'contrib' => []];
        $breakdown[$key]['count']++;
        $breakdown[$key]['score'] += $value;
        if ($label !== null && $label !== '') {
            $breakdown[$key]['contrib'][$label] = ($breakdown[$key]['contrib'][$label] ?? 0) + abs($value);
        }
    }

    /** 單一來源上限：超過的部分扣回（半徑內公車站牌可能 20+ 個，不加上限會淹掉其他訊號） */
    private function applyCaps(array &$breakdown): void
    {
        foreach (config('petmin.demand.caps', []) as $key => $cap) {
            if (isset($breakdown[$key]) && $breakdown[$key]['score'] > $cap) {
                $breakdown[$key]['score'] = (float) $cap;
                $breakdown[$key]['capped'] = true;
            }
        }
    }

    /** 樣本取貢獻最大的 3 個（同站多個出口合併成一個站名） */
    private function finalizeBreakdown(array $breakdown): array
    {
        foreach ($breakdown as &$part) {
            arsort($part['contrib']);
            $part['samples'] = array_slice(array_keys($part['contrib']), 0, 3);
            $part['score'] = round($part['score'], 2);
            unset($part['contrib']);
        }

        return $breakdown;
    }

    private function linear(float $d, float $radius): float
    {
        return $d >= $radius ? 0.0 : 1 - $d / $radius;
    }

    private function donut(float $d, float $inner, float $outer): float
    {
        if ($d >= $outer) {
            return 0.0;
        }

        return $d < $inner ? $d / $inner : 1 - ($d - $inner) / ($outer - $inner);
    }

    /** @return array<string, list<array>> 以 BUCKET_M 分桶的點（每點 [0]=x、[1]=y） */
    private function index(array $points): array
    {
        $index = [];
        foreach ($points as $p) {
            $index[(int) floor($p[0] / self::BUCKET_M).':'.(int) floor($p[1] / self::BUCKET_M)][] = $p;
        }

        return $index;
    }

    /** 鄰近 3×3 桶內的點與距離（半徑皆 ≤ BUCKET_M，不會漏） @return \Generator<array{0: array, 1: float}> */
    private function near(array $index, float $x, float $y): \Generator
    {
        $bx = (int) floor($x / self::BUCKET_M);
        $by = (int) floor($y / self::BUCKET_M);
        for ($i = $bx - 1; $i <= $bx + 1; $i++) {
            for ($j = $by - 1; $j <= $by + 1; $j++) {
                foreach ($index["{$i}:{$j}"] ?? [] as $p) {
                    yield [$p, hypot($p[0] - $x, $p[1] - $y)];
                }
            }
        }
    }

    /** 最近一點的距離：由內往外一圈圈掃桶，最近候選已比下一圈的最短可能距離還近就停 */
    private function nearestDistance(array $index, float $x, float $y): float
    {
        if (! $index) {
            return INF;
        }
        $bx = (int) floor($x / self::BUCKET_M);
        $by = (int) floor($y / self::BUCKET_M);
        $best = INF;
        for ($ring = 0; $ring <= 200; $ring++) {
            if ($best <= ($ring - 1) * self::BUCKET_M) {
                break;
            }
            for ($i = $bx - $ring; $i <= $bx + $ring; $i++) {
                for ($j = $by - $ring; $j <= $by + $ring; $j++) {
                    if (max(abs($i - $bx), abs($j - $by)) !== $ring) {
                        continue;
                    }
                    foreach ($index["{$i}:{$j}"] ?? [] as $p) {
                        $best = min($best, hypot($p[0] - $x, $p[1] - $y));
                    }
                }
            }
        }

        return $best;
    }

    /**
     * 候選名次：候選＝candidate_spacing_m 內分數最高的格子（局部最高點），依分數排名。
     * 熱點外圍的低分格子旁邊一定有更高分的格子，不會自成候選；兩個候選彼此必然相距超過 spacing。
     * 重算時做一次存進 candidate_rank，報表翻頁、篩選、匯出都直接查欄位。
     */
    private function rankCandidates(array $rows): array
    {
        $spacing = (float) config('petmin.demand.candidate_spacing_m');
        $index = [];
        // 只在臺北市格子之間比：新北只有捷運資料、資料不對等；新北高分格也不能壓掉市界旁的臺北格
        $ranked = fn (array $r) => $r['score'] > 0 && $r['district'] !== null;
        foreach ($rows as $i => $r) {
            if ($ranked($r)) {
                $index[(int) floor($r['_x'] / self::BUCKET_M).':'.(int) floor($r['_y'] / self::BUCKET_M)][] = [$r['_x'], $r['_y'], $i];
            }
        }
        // 同分以格號定先後，避免兩個等高的相鄰格互相讓位都落選
        $outranks = fn (int $a, int $b) => $rows[$a]['score'] > $rows[$b]['score']
            || ($rows[$a]['score'] === $rows[$b]['score'] && strcmp($rows[$a]['cell'], $rows[$b]['cell']) < 0);

        $peaks = [];
        foreach ($rows as $i => $r) {
            if (! $ranked($r)) {
                continue;
            }
            foreach ($this->near($index, $r['_x'], $r['_y']) as [$p, $d]) {
                if ($p[2] !== $i && $d < $spacing && $outranks($p[2], $i)) {
                    continue 2;
                }
            }
            $peaks[] = $i;
        }
        usort($peaks, fn ($a, $b) => $outranks($a, $b) ? -1 : 1);
        foreach ($peaks as $rank => $i) {
            $rows[$i]['candidate_rank'] = $rank + 1;
        }

        return array_map(function ($r) {
            unset($r['_x'], $r['_y']);

            return $r;
        }, $rows);
    }

    /** @return Collection<int, DemandCell> 候選復設點前 N 名（名次由 recompute 決定） */
    public function topCandidates(?int $limit = null): Collection
    {
        return DemandCell::query()->whereNotNull('candidate_rank')->orderBy('candidate_rank')
            ->limit($limit ?? (int) config('petmin.demand.top_n'))->get();
    }

    /**
     * 行政區缺桶排行：缺桶熱點數（候選地點分數 ≥ hotspot_min_score）、清潔箱組數、狗便袋箱數、
     * 有袋沒桶數與比例、區內最高分地點。依熱點數、有袋沒桶比例排序。
     *
     * @return list<array{district: string, hotspots: int, candidates: int, clean_boxes: int, bag_boxes: int, bag_gaps: int, bag_gap_pct: ?int, top: ?DemandCell}>
     */
    public function districtRanking(): array
    {
        $min = (float) config('petmin.demand.hotspot_min_score');
        $cells = DemandCell::query()->whereNotNull('candidate_rank')->get(['id', 'cell', 'ix', 'iy', 'district', 'score', 'breakdown', 'candidate_rank']);
        $cleanBoxes = TrashBin::query()->active()->kind(TrashBin::KIND_CLEAN_BOX)->selectRaw('district, sum(box_count) as n')->groupBy('district')->pluck('n', 'district');
        $bagBoxes = TrashBin::query()->active()->kind(TrashBin::KIND_DOG_BAG_BOX)->selectRaw('district, count(*) as n')->groupBy('district')->pluck('n', 'district');
        $bagGaps = app(BagBoxGapService::class)->gaps()->countBy('district');

        return collect(TaipeiBoundary::names())->map(function (string $district) use ($cells, $min, $cleanBoxes, $bagBoxes, $bagGaps) {
            $mine = $cells->where('district', $district);
            $bags = (int) ($bagBoxes[$district] ?? 0);
            $gaps = (int) ($bagGaps[$district] ?? 0);

            return [
                'district' => $district,
                'hotspots' => $mine->where('score', '>=', $min)->count(),
                'candidates' => $mine->count(),
                'clean_boxes' => (int) ($cleanBoxes[$district] ?? 0),
                'bag_boxes' => $bags,
                'bag_gaps' => $gaps,
                'bag_gap_pct' => $bags > 0 ? (int) round(100 * $gaps / $bags) : null,
                'top' => $mine->sortBy('candidate_rank')->first(),
            ];
        })->sortBy([['hotspots', 'desc'], ['bag_gap_pct', 'desc']])->values()->all();
    }

    /**
     * 數據判讀：提案論點背後的數字（桶留在觀光區、袋箱附近有沒有桶、各區落差）。只依桶位資料，
     * 桶位異動時由 BagBoxGapService::forgetCache() 一併清除；快取只放純陣列。
     *
     * @return array{clean_points: int, area_sites: int, area_sets: int, points_near_areas_pct: ?int, bag_boxes: int,
     *     bags_with_bin_100m_pct: ?int, bags_with_bin_300m_pct: ?int, sets_per_bag: array{max: ?array, min: ?array, by_district: array<string, float>}, synced_at: ?string}
     */
    public function insights(): array
    {
        return Cache::remember('demand.insights', 600, function () {
            $bins = TrashBin::query()->active()->get(['source', 'kind', 'district', 'latitude', 'longitude', 'box_count']);
            $points = $bins->where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX)->values();
            $areas = $bins->where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX_AREA)->values();
            $cleanBoxes = $bins->where('kind', TrashBin::KIND_CLEAN_BOX)->values();
            $bags = $bins->where('kind', TrashBin::KIND_DOG_BAG_BOX)->values();
            $within = fn (TrashBin $p, $pool, float $m) => $pool->contains(fn (TrashBin $q) => Geo::distanceMeters((float) $p->latitude, (float) $p->longitude, (float) $q->latitude, (float) $q->longitude) <= $m);
            $pct = fn (int $n, int $of) => $of > 0 ? (int) round(100 * $n / $of) : null;

            // 每個狗便袋箱分到幾組清潔箱（只比有袋箱的區）：居民遛狗的地方分到多少桶
            $ratios = $bags->groupBy('district')->map(fn ($districtBags, $district) => round(
                $cleanBoxes->where('district', $district)->sum('box_count') / $districtBags->count(), 1))->sortDesc();
            $pick = fn (?string $district) => $district === null ? null : ['district' => $district, 'ratio' => (float) $ratios[$district]];

            return [
                'clean_points' => $points->count(),
                'area_sites' => $areas->count(),
                'area_sets' => (int) $areas->sum('box_count'),
                'points_near_areas_pct' => $pct($points->filter(fn ($p) => $within($p, $areas, 500))->count(), $points->count()),
                'bag_boxes' => $bags->count(),
                'bags_with_bin_100m_pct' => $pct($bags->filter(fn ($b) => $within($b, $cleanBoxes, 100))->count(), $bags->count()),
                'bags_with_bin_300m_pct' => $pct($bags->filter(fn ($b) => $within($b, $cleanBoxes, 300))->count(), $bags->count()),
                'sets_per_bag' => ['max' => $pick($ratios->keys()->first()), 'min' => $pick($ratios->keys()->last()), 'by_district' => $ratios->all()],
                'synced_at' => TrashBin::max('last_seen_in_source_at'),
            ];
        });
    }

    /** @return Collection<int, DemandCell> 撤除風險：家戶垃圾亂丟回報集中格 */
    public function dumpingRisk(int $limit = 20): Collection
    {
        return $this->spacedTop('dumping_score', $limit);
    }

    /** 依欄位排序取前 N，相距 candidate_spacing_m 內的格子只留最高的一格（同一熱點不重複佔名額） */
    private function spacedTop(string $column, int $limit): Collection
    {
        $grid = DemandGrid::fromConfig();
        $spacing = (float) config('petmin.demand.candidate_spacing_m');
        $picked = [];
        foreach (DemandCell::query()->where($column, '>', 0)->orderByDesc($column)->get(['id', 'latitude', 'longitude'])->all() as $cell) {
            [$x, $y] = $grid->toMeters((float) $cell->latitude, (float) $cell->longitude);
            foreach ($picked as [$px, $py]) {
                if (hypot($x - $px, $y - $py) < $spacing) {
                    continue 2;
                }
            }
            $picked[$cell->id] = [$x, $y];
            if (count($picked) >= $limit) {
                break;
            }
        }
        $order = array_flip(array_keys($picked));

        return DemandCell::query()->whereKey(array_keys($picked))->get()->sortBy(fn (DemandCell $c) => $order[$c->id])->values();
    }
}
