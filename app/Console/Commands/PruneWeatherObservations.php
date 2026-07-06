<?php

namespace App\Console\Commands;

use App\Models\WeatherObservation;
use Illuminate\Console\Command;

/**
 * 觀測歷史只保留 7 天（spec §4.2），daily prune。
 */
class PruneWeatherObservations extends Command
{
    protected $signature = 'hazdar:prune-weather';

    protected $description = '刪除 7 天前的 weather_observations';

    public function handle(): int
    {
        $deleted = WeatherObservation::where('observed_at', '<', now()->subDays(7))->delete();
        $this->info("Pruned {$deleted} weather observations");

        return self::SUCCESS;
    }
}
