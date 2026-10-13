<?php

namespace App\Console\Commands;

use App\Models\TrashBin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileObject;
use Throwable;

/**
 * 從本機 CSV 匯入官方桶位（database/data/*.csv，欄位 district,address,lng,lat,note）。
 * 不依賴現場網路；重跑 upsert 冪等。既有桶的 status / last_verified_at 不覆寫（眾包驗證結果優先）。
 */
abstract class ImportTrashBins extends Command
{
    abstract protected function kind(): string;

    abstract protected function source(): string;

    abstract protected function defaultFile(): string;

    /** 狗便袋箱總表的 note 是「N處」，清潔箱固定 1 */
    protected function boxCount(array $row): int
    {
        return 1;
    }

    public function handle(): int
    {
        $file = $this->option('file') ?: $this->defaultFile();

        try {
            if (! is_readable($file)) {
                throw new RuntimeException("找不到檔案：{$file}");
            }

            $csv = new SplFileObject($file);
            $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
            $header = null;
            $upserted = 0;
            $skipped = 0;

            foreach ($csv as $line) {
                if ($header === null) {
                    $header = array_map(fn ($h) => trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF"), $line);
                    foreach (['district', 'address', 'lng', 'lat'] as $required) {
                        if (! in_array($required, $header, true)) {
                            throw new RuntimeException("CSV 缺少欄位 {$required}，實際表頭：".implode(',', $header));
                        }
                    }

                    continue;
                }
                if (count($line) < count($header)) {
                    $skipped++;

                    continue;
                }
                $row = array_combine($header, array_map('trim', array_slice($line, 0, count($header))));
                if (! is_numeric($row['lat']) || ! is_numeric($row['lng']) || $row['address'] === '') {
                    $skipped++;

                    continue;
                }

                TrashBin::updateOrCreate(
                    ['external_id' => $this->externalId($row)],
                    [
                        'source' => $this->source(),
                        'kind' => $this->kind(),
                        'district' => mb_substr($row['district'], 0, 16) ?: null,
                        'address' => mb_substr($row['address'], 0, 255),
                        'note' => mb_substr($row['note'] ?? '', 0, 255) ?: null,
                        'latitude' => (float) $row['lat'],
                        'longitude' => (float) $row['lng'],
                        'box_count' => $this->boxCount($row),
                    ],
                );
                $upserted++;
            }

            $this->info(sprintf('%s 匯入 %d 筆（略過 %d）；目前共 %d 筆',
                TrashBin::KIND_LABELS[$this->kind()], $upserted, $skipped, TrashBin::kind($this->kind())->count()));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('petmin: trash bin import failed', ['kind' => $this->kind(), 'error' => $e->getMessage()]);
            $this->error("匯入失敗：{$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /** 來源無 id：以 kind + 地址 + 座標 hash 當穩定鍵 */
    private function externalId(array $row): string
    {
        return $this->kind().'-'.substr(md5($row['address'].'|'.round((float) $row['lat'], 6).'|'.round((float) $row['lng'], 6)), 0, 16);
    }
}
