<?php

namespace App\Console\Commands;

use App\Models\Report;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class BackfillReportAddresses extends Command
{
    protected $signature = 'reports:backfill-addresses {--force : 覆蓋已有地址的紀錄}';

    protected $description = '對資料庫中缺少地址的通報，呼叫 Nominatim reverse geocode 補上縣市/區';

    public function handle(): int
    {
        $query = Report::query();

        if (! $this->option('force')) {
            $query->whereNull('address');
        }

        $reports = $query->get(['id', 'latitude', 'longitude']);

        if ($reports->isEmpty()) {
            $this->info('所有通報已有地址，無需補跑。');

            return self::SUCCESS;
        }

        $this->info("共 {$reports->count()} 筆通報需要補跑 reverse geocode…");
        $bar = $this->output->createProgressBar($reports->count());
        $bar->start();

        $client = new Client([
            'headers' => ['User-Agent' => 'Petmin/1.0'],
            'timeout' => 8,
        ]);

        $ok = 0;
        $error = 0;

        foreach ($reports as $report) {
            try {
                $resp = $client->get('https://nominatim.openstreetmap.org/reverse', [
                    'query' => [
                        'lat' => $report->latitude,
                        'lon' => $report->longitude,
                        'format' => 'json',
                        'accept-language' => 'zh-TW',
                    ],
                ]);

                $data = json_decode((string) $resp->getBody(), true);
                $a = $data['address'] ?? [];

                $city = $a['city'] ?? $a['county'] ?? $a['state'] ?? '';
                $district = $a['city_district'] ?? $a['suburb'] ?? $a['town'] ?? $a['village'] ?? '';
                $parts = array_filter([$city, $district]);
                $address = implode(' ', $parts) ?: null;

                $report->update(['address' => $address]);
                $ok++;

            } catch (\Throwable $e) {
                $error++;
                $this->newLine();
                $this->warn("Report #{$report->id} 失敗：{$e->getMessage()}");
            }

            $bar->advance();

            // Nominatim 使用政策：最多 1 req/sec
            usleep(1_200_000);
        }

        $bar->finish();
        $this->newLine();
        $this->info("完成：成功 {$ok} 筆，失敗 {$error} 筆。");

        return self::SUCCESS;
    }
}
