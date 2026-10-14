<?php

namespace App\Console\Commands;

use App\Models\DemandProxy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileObject;
use Throwable;

/**
 * 捷運出入口（人流 proxy）：mrt_exits.csv（臺北捷運車站出入口座標，UTF-8）＋ mrt_stations.csv 的站權重。
 * 一站的權重平分給各出口——大站不會因為出口多就被重複放大。整批取代既有出口列。
 */
class ImportMrtExits extends Command
{
    protected $signature = 'proxies:import-mrt-exits
        {--exits= : 出入口 CSV，預設 database/data/mrt_exits.csv}
        {--stations= : 站點權重 CSV（name,lng,lat,weight），預設 database/data/mrt_stations.csv}';

    protected $description = '匯入捷運出入口座標（站權重平分給各出口）作為人流 proxy';

    public function handle(): int
    {
        try {
            $weights = $this->stationWeights($this->option('stations') ?: database_path('data/mrt_stations.csv'));
            $exits = $this->exits($this->option('exits') ?: database_path('data/mrt_exits.csv'));

            $missing = collect($exits)->pluck('station')->unique()->reject(fn ($s) => isset($weights[$this->normalize($s)]))->values();
            if ($missing->isNotEmpty()) {
                throw new RuntimeException('站點權重檔找不到這些站：'.$missing->implode('、'));
            }

            $perStation = collect($exits)->countBy('station');
            DB::transaction(function () use ($exits, $weights, $perStation) {
                DemandProxy::where('kind', DemandProxy::KIND_MRT_EXIT)->delete();
                foreach ($exits as $exit) {
                    DemandProxy::create([
                        'kind' => DemandProxy::KIND_MRT_EXIT,
                        'external_id' => $exit['name'],
                        'name' => $exit['name'],
                        'latitude' => $exit['lat'],
                        'longitude' => $exit['lng'],
                        'weight' => round($weights[$this->normalize($exit['station'])] / $perStation[$exit['station']], 4),
                        'meta' => ['station' => $exit['station']],
                    ]);
                }
            });

            $this->info('捷運匯入 '.count($exits)." 個出入口（{$perStation->count()} 站）");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('petmin: mrt exits import failed', ['error' => $e->getMessage()]);
            $this->error("匯入失敗：{$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /** @return array<string, float> 正規化站名 => 權重 */
    private function stationWeights(string $file): array
    {
        $weights = [];
        foreach ($this->rows($file, ['name', 'weight']) as $row) {
            if (is_numeric($row['weight'])) {
                $weights[$this->normalize($row['name'])] = (float) $row['weight'];
            }
        }

        return $weights;
    }

    /** @return list<array{name: string, station: string, lat: float, lng: float}> */
    private function exits(string $file): array
    {
        $exits = [];
        foreach ($this->rows($file, ['出入口名稱', '經度', '緯度']) as $row) {
            if (! is_numeric($row['經度']) || ! is_numeric($row['緯度'])) {
                continue;
            }
            $name = trim($row['出入口名稱']);
            // 「頂埔站出口1」「中山國中站出口」「南港展覽館站出口2A」「台北車站M1」→ 站名
            $base = preg_replace('/(出口.*|M\d+)$/u', '', $name);
            $exits[] = [
                'name' => $name,
                'station' => str_ends_with($base, '站') ? $base : $base.'站',
                'lat' => (float) $row['緯度'],
                'lng' => (float) $row['經度'],
            ];
        }

        return $exits;
    }

    /** @return \Generator<array<string, string>> */
    private function rows(string $file, array $required): \Generator
    {
        if (! is_readable($file)) {
            throw new RuntimeException("找不到檔案：{$file}");
        }
        $csv = new SplFileObject($file);
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $header = null;
        foreach ($csv as $line) {
            if ($header === null) {
                $header = array_map(fn ($h) => trim((string) $h, " \t\xEF\xBB\xBF"), $line);
                if (array_diff($required, $header)) {
                    throw new RuntimeException(basename($file).' 表頭須含 '.implode(',', $required));
                }

                continue;
            }
            if (count($line) >= count($header)) {
                yield array_combine($header, array_slice($line, 0, count($header)));
            }
        }
    }

    /** 與 ImportMrtStations 同一套站名正規化（OD 站名不帶「站」） */
    private function normalize(string $name): string
    {
        return preg_replace('/\s+|站$|捷運/u', '', trim($name)) ?: trim($name);
    }
}
