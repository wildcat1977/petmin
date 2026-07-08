<?php

namespace App\Console\Commands;

use App\Models\Floodgate;
use App\Services\DataSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** 臺北市疏散門靜態位置同步（spec §11.5 P2，daily；即時狀態端點不可達，只放位置）。 */
class SyncFloodgates extends Command
{
    protected $signature = 'hazdar:sync-floodgates';

    protected $description = '同步 data.taipei 疏散門位置並落地 floodgates';

    public function handle(DataSourceHealth $health): int
    {
        $config = config('hazdar.sources.floodgates');

        try {
            if (empty($config['resource_id'])) {
                throw new RuntimeException('TAIPEI_FLOODGATE_RESOURCE_ID 未設定');
            }
            $response = Http::timeout(15)->get(sprintf($config['url'], $config['resource_id']));
            if (! $response->successful()) {
                throw new RuntimeException("data.taipei HTTP {$response->status()}");
            }
            $rows = data_get($response->json(), 'result.results', []);
            if (empty($rows)) {
                throw new RuntimeException('data.taipei 回應無資料列');
            }

            $upserted = 0;
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                $park = trim((string) ($row['riverside_park'] ?? ''));
                if ($name === '') {
                    continue;
                }
                Floodgate::updateOrCreate(
                    ['external_key' => ($park !== '' ? $park.'_' : '').$name],
                    [
                        'name' => mb_substr($name, 0, 255),
                        'description' => trim((string) ($row['description'] ?? '')) ?: null,
                        'latitude' => is_numeric($row['latitude'] ?? null) ? (float) $row['latitude'] : null,
                        'longitude' => is_numeric($row['longitude'] ?? null) ? (float) $row['longitude'] : null,
                    ],
                );
                $upserted++;
            }

            $health->recordSuccess('floodgates');
            $this->info("Floodgates synced: {$upserted} / ".Floodgate::count().' total');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('hazdar: floodgate sync failed', ['error' => $e->getMessage()]);
            $health->recordFailure('floodgates', $e->getMessage());
            $this->error("Floodgate sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
