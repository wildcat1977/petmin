<?php

namespace App\Support;

use App\Models\ExternalAlert;
use App\Models\Floodgate;
use App\Models\InfoLink;
use App\Models\PumpStation;
use App\Models\Shelter;
use App\Models\TransitAlert;
use App\Services\DataSourceHealth;
use Illuminate\Support\Collection;

/** /info 頁面與首頁卡共用的資料彙整（只讀落地資料）。 */
class CityInfo
{
    /** 目前有效且涵蓋台北市的停班停課（無則 null → 顯示正常上班上課）。 */
    public static function closure(): ?ExternalAlert
    {
        return ExternalAlert::where('alert_type', '停班停課')
            ->active()->coversTaipei()
            ->latest('effective_at')
            ->first();
    }

    /**
     * 停課資料源（NCDR）是否非「正常綠燈」——延遲(yellow)或中斷(red)。
     * 供停課卡在「查無停課」時判斷：來源不新鮮就別拍胸脯說「正常上班上課」，
     * 改提示以官方公告為準，避免事件期間來源延遲卻誤告正常（安全考量）。
     */
    public static function closureSourceStale(): bool
    {
        return app(DataSourceHealth::class)->status('ncdr_alerts')['status'] !== DataSourceHealth::STATUS_OK;
    }

    /** @return Collection<int, PumpStation> */
    public static function pumpStations(): Collection
    {
        return PumpStation::orderBy('name')->get();
    }

    public static function runningPumpCount(): int
    {
        return PumpStation::where('pumb_status', PumpStation::STATUS_RUNNING)->count();
    }

    /** 台北市行政區顯示順序；未知/無 district 者歸入「其他」置於最後。 */
    private const DISTRICT_ORDER = [
        '中正區', '大同區', '中山區', '松山區', '大安區', '萬華區',
        '信義區', '士林區', '北投區', '內湖區', '南港區', '文山區',
    ];

    /**
     * 抽水站依行政區分組（/info 頁面用 Alpine 摺疊呈現，避免長列表一次全展開）。
     *
     * @return array<string, Collection<int, PumpStation>>
     */
    public static function pumpStationsByDistrict(): array
    {
        $byDistrict = PumpStation::orderBy('name')->get()->groupBy(fn (PumpStation $p) => $p->district ?: '其他');

        $ordered = [];
        foreach (self::DISTRICT_ORDER as $district) {
            if ($byDistrict->has($district)) {
                $ordered[$district] = $byDistrict->get($district);
            }
        }
        // 未列於既定順序的區（含「其他」）附加於最後
        foreach ($byDistrict as $district => $rows) {
            if (! isset($ordered[$district])) {
                $ordered[$district] = $rows;
            }
        }

        return $ordered;
    }

    /** @return Collection<int, Floodgate> */
    public static function floodgates(): Collection
    {
        return Floodgate::whereNotNull('latitude')->orderBy('name')->get();
    }

    public static function openShelterCount(): int
    {
        return Shelter::where('is_open', true)->count();
    }

    /** 已取得座標、可上圖的收容所數（is_open 為災時手動旗標，平時多為 0，不足以代表可查詢的收容所量）。 */
    public static function mappableShelterCount(): int
    {
        return Shelter::whereNotNull('latitude')->count();
    }

    public static function totalShelterCount(): int
    {
        return Shelter::count();
    }

    /** @return Collection<int, InfoLink> 清運與環保局手動連結（無 API，維持手動） */
    public static function manualSanitation(): Collection
    {
        return InfoLink::where('section', InfoLink::SECTION_SANITATION)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * 三系統（metro/gondola/bus）固定順序回傳；TDX 未啟用（table 全空）時回傳 [] 整段隱藏。
     * 公車常態無異常列（僅災害相關才進 table），啟用時仍需輸出單一正常列，不可因無資料列而消失。
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public static function transitBySystem(): array
    {
        if (! TransitAlert::exists()) {
            return [];
        }

        $bySystem = TransitAlert::orderBy('system')->get()->groupBy('system');

        $result = [];
        foreach ([TransitAlert::SYSTEM_METRO, TransitAlert::SYSTEM_GONDOLA, TransitAlert::SYSTEM_BUS] as $system) {
            $abnormal = ($bySystem->get($system) ?? collect())
                ->filter(fn ($a) => $a->status !== TransitAlert::STATUS_NORMAL)
                ->map(fn ($a) => [
                    'scope' => $a->scope_name,
                    'status' => $a->status,      // 0 停駛 / 1 正常 / 2 調整
                    'title' => $a->title,
                ])->values()->all();

            $result[$system] = $abnormal !== [] ? $abnormal : [['scope' => null, 'status' => TransitAlert::STATUS_NORMAL, 'title' => null]];
        }

        return $result;
    }

    /** 捷運整體狀態（優先序 停駛(0) > 調整(2) > 正常(1)；無資料 → null）。 */
    public static function metroOverallStatus(): ?int
    {
        return self::overallStatus(TransitAlert::SYSTEM_METRO);
    }

    /**
     * 公車整體狀態。公車常態無列（僅災害相關才進 table），故只要 TDX 啟用（transit_alerts 非空）
     * 且無異常列即視為正常(1)；TDX 完全未啟用（table 全空）才回 null。
     */
    public static function busOverallStatus(): ?int
    {
        if (! TransitAlert::exists()) {
            return null;
        }

        return self::overallStatus(TransitAlert::SYSTEM_BUS) ?? 1;
    }

    /** 優先序 停駛(0) > 調整(2) > 正常(1)；該系統無列 → null。 */
    private static function overallStatus(string $system): ?int
    {
        $rows = TransitAlert::where('system', $system)->pluck('status');
        if ($rows->isEmpty()) {
            return null;
        }
        if ($rows->contains(0)) {
            return 0; // 任一線停駛 → 停駛
        }
        if ($rows->contains(2)) {
            return 2; // 任一線調整 → 調整
        }

        return 1; // 全部正常
    }
}
