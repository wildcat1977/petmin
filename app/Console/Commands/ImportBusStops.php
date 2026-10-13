<?php

namespace App\Console\Commands;

use App\Models\DemandProxy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileObject;
use Throwable;

/** 公車站牌（人流 proxy）：database/data/bus_stops.csv，欄位 stop_id,name,lng,lat（SHP 已轉 WGS84）。 */
class ImportBusStops extends Command
{
    protected $signature = 'proxies:import-bus-stops {--file= : CSV 路徑，預設 database/data/bus_stops.csv}';

    protected $description = '匯入臺北市公車站牌位置作為人流 proxy';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/bus_stops.csv');

        try {
            if (! is_readable($file)) {
                throw new RuntimeException("找不到檔案：{$file}");
            }
            $csv = new SplFileObject($file);
            $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
            $header = null;
            $n = 0;
            foreach ($csv as $line) {
                if ($header === null) {
                    $header = array_map('trim', $line);
                    if (array_diff(['stop_id', 'name', 'lng', 'lat'], $header)) {
                        throw new RuntimeException('CSV 表頭須為 stop_id,name,lng,lat');
                    }

                    continue;
                }
                if (count($line) < 4) {
                    continue;
                }
                $row = array_combine($header, array_slice($line, 0, count($header)));
                if (! is_numeric($row['lat']) || ! is_numeric($row['lng'])) {
                    continue;
                }
                DemandProxy::updateOrCreate(
                    ['kind' => DemandProxy::KIND_BUS_STOP, 'external_id' => (string) $row['stop_id']],
                    ['name' => mb_substr($row['name'], 0, 255), 'latitude' => (float) $row['lat'], 'longitude' => (float) $row['lng'], 'weight' => 1],
                );
                $n++;
            }
            $this->info("公車站牌匯入 {$n} 筆");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('petmin: bus stop import failed', ['error' => $e->getMessage()]);
            $this->error("匯入失敗：{$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
