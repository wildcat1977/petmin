<?php

namespace App\Console\Commands;

use App\Services\DemandScoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecomputeDemand extends Command
{
    protected $signature = 'demand:recompute';

    protected $description = '重算各 geohash6 網格的垃圾桶需求分數（demand_cells）';

    public function handle(DemandScoreService $service): int
    {
        try {
            $n = $service->recompute();
            Cache::forget('map.demand');
            $this->info("需求分數重算完成：{$n} 格");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('petmin: demand recompute failed', ['error' => $e->getMessage()]);
            $this->error("重算失敗：{$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
