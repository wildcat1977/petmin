<?php

namespace App\Console\Commands;

use App\Models\DemandProxy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileObject;
use Throwable;

/**
 * 捷運站（人流 proxy）：stations CSV 欄位 name,lng,lat[,weight]。
 * 另可給 --od= 月檔（臺北捷運每日分時各站 OD 流量統計）把各站出站人次加總後正規化成 0~1 當 weight。
 * OD 檔欄位以表頭名稱判斷（含「出站」的欄位 = 站名、含「人次」的欄位 = 數量），找不到就大聲失敗。
 */
class ImportMrtStations extends Command
{
    protected $signature = 'proxies:import-mrt {--file= : 站點 CSV，預設 database/data/mrt_stations.csv} {--od= : 月 OD 流量 CSV（選填）}';

    protected $description = '匯入捷運站點（可加月 OD 檔算出站量權重）作為人流 proxy';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/mrt_stations.csv');

        try {
            if (! is_readable($file)) {
                throw new RuntimeException("找不到檔案：{$file}（請放站名,lng,lat 的 CSV）");
            }

            $volumes = $this->option('od') ? $this->exitVolumes($this->option('od')) : [];
            $max = $volumes ? max($volumes) : 0;

            $csv = new SplFileObject($file);
            $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
            $header = null;
            $n = 0;
            $unmatched = [];
            foreach ($csv as $line) {
                if ($header === null) {
                    $header = array_map(fn ($h) => trim((string) $h, " \t\xEF\xBB\xBF"), $line);
                    if (array_diff(['name', 'lng', 'lat'], $header)) {
                        throw new RuntimeException('站點 CSV 表頭須含 name,lng,lat');
                    }

                    continue;
                }
                if (count($line) < 3) {
                    continue;
                }
                $row = array_combine($header, array_slice($line, 0, count($header)));
                if (! is_numeric($row['lat']) || ! is_numeric($row['lng'])) {
                    continue;
                }
                $name = trim($row['name']);
                $weight = isset($row['weight']) && is_numeric($row['weight']) ? (float) $row['weight'] : 1.0;
                if ($max > 0) {
                    $key = $this->normalizeName($name);
                    if (isset($volumes[$key])) {
                        $weight = round($volumes[$key] / $max, 3);
                    } else {
                        $unmatched[] = $name;
                    }
                }
                DemandProxy::updateOrCreate(
                    ['kind' => DemandProxy::KIND_MRT, 'external_id' => $key ?? $this->normalizeName($name)],
                    ['name' => $name, 'latitude' => (float) $row['lat'], 'longitude' => (float) $row['lng'], 'weight' => $weight,
                        'meta' => $max > 0 ? ['exit_volume' => $volumes[$this->normalizeName($name)] ?? null] : null],
                );
                $n++;
            }
            $this->info("捷運站匯入 {$n} 筆".($max > 0 ? '（含 OD 出站量權重）' : '（權重一律 1）'));
            if ($unmatched) {
                $this->warn('OD 檔找不到這些站（權重維持 1）：'.implode('、', $unmatched));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('petmin: mrt import failed', ['error' => $e->getMessage()]);
            $this->error("匯入失敗：{$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /** @return array<string, int> 正規化站名 => 月出站人次 */
    private function exitVolumes(string $odFile): array
    {
        if (! is_readable($odFile)) {
            throw new RuntimeException("找不到 OD 檔：{$odFile}");
        }
        $csv = new SplFileObject($odFile);
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $exitIdx = $countIdx = null;
        $volumes = [];
        foreach ($csv as $i => $line) {
            if ($i === 0) {
                foreach ($line as $idx => $h) {
                    $h = trim((string) $h, " \t\xEF\xBB\xBF");
                    if ($exitIdx === null && str_contains($h, '出站')) {
                        $exitIdx = $idx;
                    }
                    if ($countIdx === null && str_contains($h, '人次')) {
                        $countIdx = $idx;
                    }
                }
                if ($exitIdx === null || $countIdx === null) {
                    throw new RuntimeException('OD 檔表頭找不到「出站」或「人次」欄位：'.implode(',', $line));
                }

                continue;
            }
            if (! isset($line[$exitIdx], $line[$countIdx]) || ! is_numeric($line[$countIdx])) {
                continue;
            }
            $key = $this->normalizeName($line[$exitIdx]);
            $volumes[$key] = ($volumes[$key] ?? 0) + (int) $line[$countIdx];
        }

        return $volumes;
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/\s+|站$|捷運/u', '', trim($name)) ?: trim($name);
    }
}
