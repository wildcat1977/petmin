<?php

namespace App\Console\Commands;

use App\Models\DemandProxy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** 狗活動區／公園（遛狗 proxy）：手刻 database/data/dog_parks.json，欄位 name,district,lat,lng,kind(dog_park|park)。 */
class ImportDogParks extends Command
{
    protected $signature = 'proxies:import-dog-parks {--file= : JSON 路徑，預設 database/data/dog_parks.json}';

    protected $description = '匯入公園狗活動區與遛狗熱點作為遛狗 proxy';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/dog_parks.json');

        try {
            if (! is_readable($file)) {
                throw new RuntimeException("找不到檔案：{$file}");
            }
            $rows = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $n = 0;
            foreach ($rows as $row) {
                if (! isset($row['name'], $row['lat'], $row['lng'])) {
                    throw new RuntimeException('每筆需有 name / lat / lng：'.json_encode($row, JSON_UNESCAPED_UNICODE));
                }
                $kind = ($row['kind'] ?? 'park') === DemandProxy::KIND_DOG_PARK ? DemandProxy::KIND_DOG_PARK : DemandProxy::KIND_PARK;
                DemandProxy::updateOrCreate(
                    ['kind' => $kind, 'external_id' => $row['name']],
                    ['name' => $row['name'], 'district' => $row['district'] ?? null, 'latitude' => (float) $row['lat'], 'longitude' => (float) $row['lng'],
                        'weight' => 1, 'meta' => ['approx' => (bool) ($row['approx'] ?? false)]],
                );
                $n++;
            }
            $this->info("狗活動區／公園匯入 {$n} 筆");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('petmin: dog park import failed', ['error' => $e->getMessage()]);
            $this->error("匯入失敗：{$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
