<?php

namespace App\Models;

use App\Support\DemandGrid;
use Illuminate\Database\Eloquent\Model;

/** DemandGrid 方格（預設 150m）需求分數快取；由 demand:recompute 整批重算。 */
class DemandCell extends Model
{
    public $timestamps = false;

    /** breakdown 各來源的顯示名稱（/candidates 與地圖明細共用） */
    public const BREAKDOWN_LABELS = [
        'mrt' => '捷運', 'bus_stop' => '公車站牌', 'dog_bag_box' => '狗便袋箱', 'dog_park' => '狗活動區', 'park' => '公園', 'petition' => '陳情',
        'report_need_bin' => '市民：需要桶', 'report_full' => '市民：桶滿了', 'report_dumping' => '市民：家戶垃圾', 'existing_bin' => '現存清潔箱',
        'bin_gap' => '缺桶加成', 'far_signal' => '市民：好遠！',
    ];

    /** 報表明細可排序的欄位（白名單：排序欄位來自網址參數） */
    public const SORTABLE = ['score', 'nearest_bin_m', 'dog_score', 'mrt_score', 'bus_score', 'report_score'];

    /** 候選地點清單（300m 去重後的名次）：報表與 CSV 匯出共用，篩選排序規則一致 */
    public function scopeCandidateList($query, ?string $district = null, string $sort = 'score', string $dir = 'desc')
    {
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'score';

        return $query->whereNotNull('candidate_rank')
            ->when($district, fn ($q) => $q->where('district', $district))
            ->orderBy($sort, $dir === 'asc' ? 'asc' : 'desc')
            ->orderBy('candidate_rank');
    }

    /** 明細標題取地名的來源順序：越像地標越前面（捷運站名比公車站牌好認） */
    private const TITLE_SOURCES = ['mrt', 'dog_park', 'park', 'dog_bag_box', 'petition', 'report_need_bin', 'report_full', 'bus_stop'];

    protected $fillable = ['cell', 'ix', 'iy', 'district', 'candidate_rank', 'nearest_bin_m', 'dog_score', 'mrt_score', 'bus_score', 'report_score', 'latitude', 'longitude', 'score', 'dumping_score', 'breakdown', 'computed_at'];

    protected function casts(): array
    {
        return [
            'ix' => 'integer',
            'candidate_rank' => 'integer',
            'nearest_bin_m' => 'integer',
            'dog_score' => 'float',
            'mrt_score' => 'float',
            'bus_score' => 'float',
            'report_score' => 'float',
            'iy' => 'integer',
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
     * @return array{cell: string, score: float, title: string, bounds: array{south: float, west: float, north: float, east: float}, outside_taipei: bool, outside_note: ?string, items: list<array{key: string, label: string, count: int, score: float, note: string}>}
     */
    public function explanation(): array
    {
        $breakdown = collect($this->breakdown);

        return [
            'cell' => $this->cell,
            'score' => $this->score,
            'title' => collect(self::TITLE_SOURCES)->flatMap(fn (string $key) => $breakdown[$key]['samples'] ?? [])->unique()->take(2)->implode('、') ?: $this->cell,
            'bounds' => DemandGrid::fromConfig()->bounds($this->ix, $this->iy),
            'outside_taipei' => $this->district === null,
            'outside_note' => $this->district === null ? __('新北市：街頭已全面撤除行人清潔箱，這裡只有捷運資料（沒有公車站牌、狗便袋箱），不列入候選排行') : null,
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
