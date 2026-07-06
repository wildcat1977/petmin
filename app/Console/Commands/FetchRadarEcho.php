<?php

namespace App\Console\Commands;

use App\Services\DataSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * 雷達回波整合圖拉取（spec §5，每 10 分鐘）。
 * 後端 proxy + cache 圖檔：前端 Leaflet ImageOverlay 讀本站 /api/radar-echo，
 * 避免 CORS 與打爆來源。
 */
class FetchRadarEcho extends Command
{
    protected $signature = 'civicshield:fetch-radar-echo';

    protected $description = '拉取 CWA 雷達回波整合圖並快取為本站圖檔';

    public function handle(DataSourceHealth $health): int
    {
        $config = config('civicshield.sources.radar_echo');

        try {
            $response = Http::timeout(10)->get($config['image_url']);
            if (! $response->successful()) {
                throw new RuntimeException("radar HTTP {$response->status()}");
            }
            if (! str_starts_with($response->header('Content-Type'), 'image/')) {
                throw new RuntimeException('radar 回應非圖片（'.$response->header('Content-Type').'）');
            }

            Storage::disk('public')->put('radar/latest.png', $response->body());
            Cache::forever('civicshield:radar:meta', [
                'fetched_at' => now()->toIso8601String(),
                'bytes' => strlen($response->body()),
            ]);

            $health->recordSuccess('radar_echo');
            $this->info('Radar echo image cached ('.strlen($response->body()).' bytes)');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('civicshield: radar echo fetch failed', ['error' => $e->getMessage()]);
            $health->recordFailure('radar_echo', $e->getMessage());
            $this->error("Radar echo fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
