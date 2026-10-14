<?php

namespace App\Models;

use App\Support\Geohash;
use Illuminate\Database\Eloquent\Model;

/** geohash6（~600m）網格需求分數快取；由 demand:recompute 整批重算。 */
class DemandCell extends Model
{
    public $timestamps = false;

    /** breakdown 各來源的顯示名稱（/candidates 與地圖明細共用） */
    public const BREAKDOWN_LABELS = [
        'mrt' => '捷運', 'bus_stop' => '公車站牌', 'dog_bag_box' => '狗便袋箱', 'dog_park' => '狗活動區', 'park' => '公園', 'petition' => '陳情',
        'report_need_bin' => '市民：需要桶', 'report_full' => '市民：桶滿了', 'report_dumping' => '市民：家戶垃圾', 'existing_bin' => '現存清潔箱',
        'bin_gap' => '缺桶加成',
    ];

    /** 明細標題取地名的來源順序：越像地標越前面（捷運站名比公車站牌好認） */
    private const TITLE_SOURCES = ['mrt', 'dog_park', 'park', 'dog_bag_box', 'petition', 'report_need_bin', 'report_full', 'bus_stop'];

    protected $fillable = ['geohash6', 'latitude', 'longitude', 'score', 'dumping_score', 'breakdown', 'computed_at'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'score' => 'float',
            'dumping_score' => 'float',
            'breakdown' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * 地圖點熱力圖的明細：分數由哪些來源組成（家戶垃圾不計入需求分數，不列）。
     *
     * @return array{geohash6: string, score: float, title: string, bounds: array{south: float, west: float, north: float, east: float}, items: list<array{key: string, label: string, count: int, score: float, note: string}>}
     */
    public function explanation(): array
    {
        $breakdown = collect($this->breakdown);

        return [
            'geohash6' => $this->geohash6,
            'score' => $this->score,
            'title' => collect(self::TITLE_SOURCES)->flatMap(fn (string $key) => $breakdown[$key]['samples'] ?? [])->unique()->take(2)->implode('、') ?: $this->geohash6,
            'bounds' => Geohash::bounds($this->geohash6),
            'items' => $breakdown->except('report_dumping')->map(fn (array $b, string $key) => [
                'key' => $key,
                'label' => __(self::BREAKDOWN_LABELS[$key] ?? $key),
                'count' => (int) $b['count'],
                'score' => round((float) $b['score'], 2),
                'note' => self::itemNote($key, $b),
            ])->sortByDesc('score')->values()->all(),
        ];
    }

    private static function itemNote(string $key, array $b): string
    {
        $parts = [];
        if ($key === 'bin_gap') {
            $parts[] = isset($b['nearest_m'])
                ? __('最近清潔箱 :m 公尺', ['m' => number_format($b['nearest_m'])])
                : __('附近沒有清潔箱');
        }
        if (! empty($b['samples'])) {
            $parts[] = implode('、', $b['samples']);
        }
        if (! empty($b['capped'])) {
            $parts[] = __('已上限');
        }

        return implode('｜', $parts);
    }
}
