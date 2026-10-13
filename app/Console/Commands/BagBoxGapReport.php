<?php

namespace App\Console\Commands;

use App\Services\BagBoxGapService;
use Illuminate\Console\Command;

/** 「有袋沒桶」清單：狗便袋箱周圍 N 公尺內無 active 清潔箱。 */
class BagBoxGapReport extends Command
{
    protected $signature = 'bins:gap-report {--meters= : 半徑公尺，預設 config petmin.bag_box_gap_meters} {--csv= : 另存 CSV 路徑}';

    protected $description = '列出周圍沒有行人專用清潔箱的狗便袋箱（有袋沒桶）';

    public function handle(BagBoxGapService $service): int
    {
        $meters = $this->option('meters') ? (int) $this->option('meters') : null;
        $gaps = $service->gaps($meters);

        $this->table(['區', '地點', '袋箱數', '最近清潔箱(m)'], $gaps->map(fn ($g) => [
            $g['district'], $g['name'], $g['box_count'], $g['nearest_m'] ?? '>'.(($meters ?? config('petmin.bag_box_gap_meters')) * 3),
        ])->all());
        $this->info("有袋沒桶：{$gaps->count()} 處");

        if ($path = $this->option('csv')) {
            $fh = fopen($path, 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, ['district', 'name', 'box_count', 'nearest_clean_box_m', 'lat', 'lng']);
            foreach ($gaps as $g) {
                fputcsv($fh, [$g['district'], $g['name'], $g['box_count'], $g['nearest_m'], $g['lat'], $g['lng']]);
            }
            fclose($fh);
            $this->info("已寫入 {$path}");
        }

        return self::SUCCESS;
    }
}
