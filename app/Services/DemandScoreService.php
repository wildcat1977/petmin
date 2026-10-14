<?php

namespace App\Services;

use App\Models\DemandCell;
use App\Models\DemandProxy;
use App\Models\Report;
use App\Models\TrashBin;
use App\Support\Geo;
use App\Support\Geohash;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 需求分數（純程式，無 AI）：以 geohash6（~600m）為格，
 *   score = (Σ proxy 權重 + 狗便袋箱 + 眾包回報（need_bin / full，已核准）× 佐證倍率) × (1 + 缺桶加成)
 *           − 現存 active 清潔箱 × penalty（缺桶加成依格中心到最近清潔箱的距離，見 config petmin.demand.bin_gap）
 *   dumping_score = 家戶垃圾亂丟（已核准）回報數 × 佐證倍率（撤除風險）
 * 每格保留 breakdown 作為證據明細，/candidates 直接顯示。
 */
class DemandScoreService
{
    /** @return int 重算後的格數 */
    public function recompute(): int
    {
        $w = config('petmin.demand.weights');
        $cells = [];

        $touch = function (string $geohash7, string $key, float $value, ?string $label = null) use (&$cells): void {
            $cell = substr($geohash7, 0, 6);
            $cells[$cell] ??= ['score' => 0.0, 'dumping' => 0.0, 'breakdown' => []];
            $cells[$cell]['score'] += $value;
            $cells[$cell]['breakdown'][$key] ??= ['count' => 0, 'score' => 0.0, 'samples' => []];
            $cells[$cell]['breakdown'][$key]['count']++;
            $cells[$cell]['breakdown'][$key]['score'] += $value;
            if ($label && count($cells[$cell]['breakdown'][$key]['samples']) < 3) {
                $cells[$cell]['breakdown'][$key]['samples'][] = $label;
            }
        };

        DemandProxy::query()->select(['kind', 'name', 'geohash7', 'weight'])->lazy()->each(function (DemandProxy $p) use ($w, $touch) {
            $base = match ($p->kind) {
                DemandProxy::KIND_MRT => $w['mrt'],
                DemandProxy::KIND_BUS_STOP => $w['bus_stop'],
                DemandProxy::KIND_DOG_PARK => $w['dog_park'],
                DemandProxy::KIND_PARK => $w['park'],
                DemandProxy::KIND_PETITION => $w['petition'],
                default => 0.0,
            };
            $touch($p->geohash7, $p->kind, $base * (float) $p->weight, $p->name);
        });

        Report::query()
            ->where('status', Report::STATUS_APPROVED)
            ->whereNull('resolved_at')
            ->whereIn('category', [Report::CATEGORY_NEED_BIN, Report::CATEGORY_FULL, Report::CATEGORY_DUMPING])
            ->whereNotNull('geohash7')
            ->select(['category', 'geohash7', 'verification_level', 'address'])
            ->lazy()
            ->each(function (Report $r) use ($w, $touch, &$cells) {
                $mult = $r->verification_level === Report::VERIFICATION_UNVERIFIED ? 1.0 : $w['corroborated_multiplier'];
                if ($r->category === Report::CATEGORY_DUMPING) {
                    $cell = substr($r->geohash7, 0, 6);
                    $cells[$cell] ??= ['score' => 0.0, 'dumping' => 0.0, 'breakdown' => []];
                    $cells[$cell]['dumping'] += $mult;
                    $cells[$cell]['breakdown']['report_dumping'] ??= ['count' => 0, 'score' => 0.0, 'samples' => []];
                    $cells[$cell]['breakdown']['report_dumping']['count']++;
                    $cells[$cell]['breakdown']['report_dumping']['score'] += $mult;

                    return;
                }
                $base = $r->category === Report::CATEGORY_NEED_BIN ? $w['report_need_bin'] : $w['report_full'];
                $touch($r->geohash7, 'report_'.$r->category, $base * $mult, $r->address);
            });

        // 狗便袋箱＝遛狗族聚集的證據（提案公式「狗便袋箱 ×2」）
        TrashBin::query()->active()->kind(TrashBin::KIND_DOG_BAG_BOX)
            ->select(['geohash7', 'address'])
            ->lazy()
            ->each(fn (TrashBin $b) => $touch($b->geohash7, TrashBin::KIND_DOG_BAG_BOX, $w['dog_bag_box'], $b->address));

        $cleanBoxes = TrashBin::query()->active()->kind(TrashBin::KIND_CLEAN_BOX)
            ->get(['geohash7', 'box_count', 'address', 'latitude', 'longitude']);
        $cleanBoxes->each(fn (TrashBin $b) => $touch($b->geohash7, 'existing_bin', -$w['existing_bin_penalty'] * max(1, $b->box_count), $b->address));

        // 單一來源上限：超過的部分從總分扣回
        $caps = config('petmin.demand.caps', []);
        foreach ($cells as &$cell) {
            foreach ($caps as $key => $cap) {
                if (isset($cell['breakdown'][$key]) && $cell['breakdown'][$key]['score'] > $cap) {
                    $cell['score'] -= $cell['breakdown'][$key]['score'] - $cap;
                    $cell['breakdown'][$key]['score'] = (float) $cap;
                    $cell['breakdown'][$key]['capped'] = true;
                }
            }
        }
        unset($cell);

        // 缺桶加成：格中心離最近現存清潔箱越遠，需求訊號（不含扣分）加成越多——人多又沒桶的地方才該排前面。
        // 用乘的不用加的：山區零星站牌離桶再遠，訊號弱就還是弱。
        $gap = config('petmin.demand.bin_gap');
        if ($gap['max_multiplier'] > 0) {
            foreach ($cells as $geohash6 => &$cell) {
                $signal = collect($cell['breakdown'])->except(['existing_bin', 'report_dumping'])->sum('score');
                if ($signal <= 0) {
                    continue;
                }
                $center = Geohash::decodeCenter($geohash6);
                $nearest = $cleanBoxes->min(fn (TrashBin $b) => Geo::distanceMeters($center['lat'], $center['lng'], (float) $b->latitude, (float) $b->longitude)) ?? INF;
                $ratio = min(1.0, max(0.0, ($nearest - $gap['start_m']) / ($gap['full_m'] - $gap['start_m'])));
                if ($ratio <= 0) {
                    continue;
                }
                $bonus = $signal * $ratio * $gap['max_multiplier'];
                $cell['score'] += $bonus;
                $cell['breakdown']['bin_gap'] = ['count' => 1, 'score' => $bonus, 'samples' => [], 'nearest_m' => is_finite($nearest) ? (int) round($nearest) : null];
            }
            unset($cell);
        }

        $now = now();
        $rows = [];
        foreach ($cells as $geohash6 => $cell) {
            $center = Geohash::decodeCenter($geohash6);
            $rows[] = [
                'geohash6' => $geohash6,
                'latitude' => round($center['lat'], 7),
                'longitude' => round($center['lng'], 7),
                'score' => round(max(0.0, $cell['score']), 2),
                'dumping_score' => round($cell['dumping'], 2),
                'breakdown' => json_encode($cell['breakdown'], JSON_UNESCAPED_UNICODE),
                'computed_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            DemandCell::query()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DemandCell::insert($chunk);
            }
        });

        return count($rows);
    }

    /** @return Collection<int, DemandCell> 候選復設點（分數高且格內沒有現存桶優先） */
    public function topCandidates(?int $limit = null): Collection
    {
        return DemandCell::query()
            ->where('score', '>', 0)
            ->orderByDesc('score')
            ->limit($limit ?? (int) config('petmin.demand.top_n'))
            ->get();
    }

    /** @return Collection<int, DemandCell> 撤除風險：家戶垃圾亂丟回報集中格 */
    public function dumpingRisk(int $limit = 20): Collection
    {
        return DemandCell::query()
            ->where('dumping_score', '>', 0)
            ->orderByDesc('dumping_score')
            ->limit($limit)
            ->get();
    }
}
