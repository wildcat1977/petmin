<?php

namespace App\Support;

use App\Models\ExternalAlert;
use App\Models\Floodgate;
use App\Models\InfoLink;
use App\Models\PumpStation;
use App\Models\Shelter;
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

    /** @return Collection<int, PumpStation> */
    public static function pumpStations(): Collection
    {
        return PumpStation::orderBy('name')->get();
    }

    public static function runningPumpCount(): int
    {
        return PumpStation::where('pumb_status', PumpStation::STATUS_RUNNING)->count();
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

    /** @return Collection<int, InfoLink> 清運與環保局手動連結（無 API，維持手動） */
    public static function manualSanitation(): Collection
    {
        return InfoLink::where('section', InfoLink::SECTION_SANITATION)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /** @return array<string, list<array<string,mixed>>> Phase 2 才有內容 */
    public static function transitBySystem(): array
    {
        return [];
    }
}
