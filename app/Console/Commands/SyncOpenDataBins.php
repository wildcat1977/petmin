<?php

namespace App\Console\Commands;

use App\Services\BagBoxGapService;
use App\Services\DemandScoreService;
use App\Services\OpenDataBinSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** 官方桶位每日同步（排程 04:00）：清潔箱、景點商圈清潔箱、狗便清潔袋箱；有異動就重算需求分數。 */
class SyncOpenDataBins extends Command
{
    protected $signature = 'bins:sync-open-data {--offline : 讀 database/data 內附快照，不連網（本機開發、測試）}';

    protected $description = '同步 data.taipei 官方桶位（清潔箱／景點商圈清潔箱／狗便清潔袋箱），官方撤除標為已確認消失';

    public function handle(OpenDataBinSync $sync, DemandScoreService $demand): int
    {
        $report = $sync->sync((bool) $this->option('offline'));

        foreach ($report as $key => $r) {
            $r['ok'] ? $this->info($r['message']) : $this->error($r['message']);
            if (! $r['ok']) {
                Log::error('petmin: open data bin sync failed', ['source' => $key, 'message' => $r['message']]);
            }
        }

        if (collect($report)->contains('changed', true)) {
            $cells = $demand->recompute();
            Cache::forget('map.demand');
            BagBoxGapService::forgetCache();
            $demand->insights(); // 數據判讀統計先算好，第一個開頁面的人不用等
            $this->info("桶位有異動，需求分數已重算：{$cells} 格");
        }

        return collect($report)->every('ok') ? self::SUCCESS : self::FAILURE;
    }
}
