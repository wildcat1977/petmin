<?php

namespace App\Services;

use App\Models\TrashBin;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 官方桶位同步：data.taipei 三個來源 → trash_bins。
 *   clean_boxes      臺北市行人專用清潔箱（逐點，API JSON）
 *   clean_box_areas  行人專用清潔箱總表（景點商圈周邊130點，一筆是一個地點、備註「N處」，API JSON）
 *   dog_bag_boxes    臺北市狗便清潔袋箱位置總表（Big5 CSV，位置欄帶「(箱編:NNNN)」）
 *
 * 規則：民眾驗證的狀態不被官方資料覆寫；從清單消失＝官方撤除（status missing + removed_from_source_at），
 * 再出現就恢復；一次消失太多視為 API 殘缺，不套用撤除並回報失敗。
 */
class OpenDataBinSync
{
    /** 依序同步：景點商圈去重要比對逐點清單，所以 clean_boxes 先跑 */
    private const SOURCES = [
        'clean_boxes' => ['kind' => TrashBin::KIND_CLEAN_BOX, 'source' => TrashBin::SOURCE_OFFICIAL_CLEAN_BOX],
        'clean_box_areas' => ['kind' => TrashBin::KIND_CLEAN_BOX, 'source' => TrashBin::SOURCE_OFFICIAL_CLEAN_BOX_AREA],
        'dog_bag_boxes' => ['kind' => TrashBin::KIND_DOG_BAG_BOX, 'source' => TrashBin::SOURCE_OFFICIAL_DOG_BAG_BOX],
    ];

    /** 台北市（含外圍）的合理座標範圍：超出就是髒資料 */
    private const LAT_RANGE = [24.9, 25.35];

    private const LNG_RANGE = [121.35, 121.75];

    /**
     * @return array<string, array{ok: bool, message: string, changed: bool}>
     */
    public function sync(bool $offline): array
    {
        $report = [];
        foreach (self::SOURCES as $key => $def) {
            try {
                $raw = $this->fetch($key, $offline);
                [$rows, $skipped] = $this->parse($key, $raw);
                if ($key === 'clean_box_areas') {
                    [$rows, $duplicates] = $this->dropAreasNearPointList($rows);
                    $skipped += $duplicates;
                }
                $result = $this->reconcile($def['kind'], $def['source'], $rows);
                $report[$key] = [
                    'ok' => $result['guarded'] === null,
                    'changed' => $result['created'] + $result['restored'] + $result['removed'] + $result['moved'] > 0,
                    'message' => sprintf('%s：%d 筆（新增 %d、恢復 %d、官方撤除 %d、略過 %d）%s',
                        $key, count($rows), $result['created'], $result['restored'], $result['removed'], $skipped,
                        $result['guarded'] ? "；{$result['guarded']}" : ''),
                ];
            } catch (\Throwable $e) {
                $report[$key] = ['ok' => false, 'changed' => false, 'message' => "{$key}：失敗——{$e->getMessage()}"];
            }
        }

        return $report;
    }

    private function fetch(string $key, bool $offline): string
    {
        $config = config("petmin.open_data.sources.{$key}");
        if ($offline) {
            if (! is_readable($config['snapshot'])) {
                throw new RuntimeException("找不到快照 {$config['snapshot']}");
            }

            return file_get_contents($config['snapshot']);
        }

        $response = Http::timeout((int) config('petmin.open_data.http_timeout'))->get($config['url']);
        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()}");
        }

        return $response->body();
    }

    /**
     * @return array{0: list<array{external_id: string, district: ?string, address: string, note: ?string, lat: float, lng: float, box_count: int}>, 1: int}
     */
    private function parse(string $key, string $raw): array
    {
        $records = $key === 'dog_bag_boxes' ? $this->csvRecords($raw) : $this->apiRecords($raw);
        $rows = [];
        $skipped = 0;
        foreach ($records as $r) {
            $lat = $this->coordinate($r['緯度'] ?? '');
            $lng = $this->coordinate($r['經度'] ?? '');
            if ($lat === null || $lng === null || $lat < self::LAT_RANGE[0] || $lat > self::LAT_RANGE[1] || $lng < self::LNG_RANGE[0] || $lng > self::LNG_RANGE[1]) {
                $skipped++;

                continue;
            }

            if ($key === 'dog_bag_boxes') {
                // 位置欄「4段2號捷運台北小巨蛋2號出口(箱編:2058)」：箱編是穩定識別碼，地址不帶它
                $position = trim($r['位置'] ?? '');
                $boxNo = preg_match('/[（(]\s*箱編[:：]\s*([\w-]+)\s*[)）]/u', $position, $m) ? $m[1] : null;
                $address = trim(($r['路名'] ?? '').preg_replace('/\s*[（(]\s*箱編[:：][^)）]*[)）]/u', '', $position));
                $boxCount = 1;
            } else {
                $boxNo = null;
                $address = trim($r['地址'] ?? '');
                $boxCount = $key === 'clean_box_areas' && preg_match('/(\d+)/', $r['備註'] ?? '', $m) ? max(1, (int) $m[1]) : 1;
            }
            if ($address === '') {
                $skipped++;

                continue;
            }

            $rows[] = [
                'box_no' => $boxNo,
                'district' => mb_substr(trim($r['行政區'] ?? ''), 0, 16) ?: null,
                'address' => mb_substr($address, 0, 255),
                'note' => mb_substr(trim($r['備註'] ?? ''), 0, 255) ?: null,
                'lat' => $lat,
                'lng' => $lng,
                'box_count' => $boxCount,
            ];
        }

        // 箱編當識別碼的前提是唯一：「0000」這類多個袋箱共用的佔位箱編、或清單裡重複出現的，改用地址＋座標
        $kind = self::SOURCES[$key]['kind'];
        $prefix = $key === 'clean_box_areas' ? 'clean_box_area' : $kind;
        $boxNoCounts = array_count_values(array_filter(array_column($rows, 'box_no'), fn ($n) => $n !== null));
        $unique = [];
        foreach ($rows as $row) {
            $boxNo = $row['box_no'];
            $row['external_id'] = $boxNo !== null && ! preg_match('/^0+$/', $boxNo) && $boxNoCounts[$boxNo] === 1
                ? "{$kind}-{$boxNo}"
                : $prefix.'-'.substr(md5($row['address'].'|'.round($row['lat'], 6).'|'.round($row['lng'], 6)), 0, 16);
            unset($row['box_no']);
            // 來源裡完全重複的列（景點商圈清單有同一地點列兩次）只算一次
            if (isset($unique[$row['external_id']])) {
                $skipped++;

                continue;
            }
            $unique[$row['external_id']] = $row;
        }

        return [array_values($unique), $skipped];
    }

    /** data.taipei API：count 與實際筆數不符＝回傳殘缺，整個來源不動 */
    private function apiRecords(string $raw): array
    {
        $json = json_decode($raw, true);
        $results = $json['result']['results'] ?? null;
        if (! is_array($results)) {
            throw new RuntimeException('API 回應格式不對（找不到 result.results）');
        }
        $count = (int) ($json['result']['count'] ?? count($results));
        if ($count !== count($results)) {
            throw new RuntimeException('API 只回了 '.count($results)."/{$count} 筆");
        }

        return $results;
    }

    /** 狗便袋箱總表：官方原檔是 Big5（cp950），快照已轉 UTF-8，兩種都吃 */
    private function csvRecords(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'CP950');
        }
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $raw), fn ($l) => trim($l) !== ''));
        $header = array_map('trim', str_getcsv(array_shift($lines) ?? ''));
        foreach (['行政區', '路名', '位置', '經度', '緯度'] as $required) {
            if (! in_array($required, $header, true)) {
                throw new RuntimeException("CSV 缺少欄位 {$required}，實際表頭：".implode(',', $header));
            }
        }

        $records = [];
        foreach ($lines as $line) {
            $values = str_getcsv($line);
            // 備註常含「新臺幣6,000元」的逗號：多出來的欄位併回最後一欄
            if (count($values) > count($header)) {
                $values = [...array_slice($values, 0, count($header) - 1), implode(',', array_slice($values, count($header) - 1))];
            }
            $records[] = array_combine($header, array_pad($values, count($header), ''));
        }

        return $records;
    }

    /** 髒座標救回：「?121.595369」之類夾雜字元的去掉，空白回 null */
    private function coordinate(mixed $value): ?float
    {
        $clean = preg_replace('/[^\d.\-]/', '', (string) $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    /** 景點商圈清單有一部分跟逐點清單是同一批桶：離逐點清單太近的跳過，避免重複扣分 @return array{0: list<array>, 1: int} */
    private function dropAreasNearPointList(array $rows): array
    {
        $limit = (float) config('petmin.open_data.area_duplicate_meters');
        $points = TrashBin::where('source', TrashBin::SOURCE_OFFICIAL_CLEAN_BOX)->get(['latitude', 'longitude']);
        $kept = array_values(array_filter($rows, fn ($row) => ! $points->contains(
            fn (TrashBin $b) => Geo::distanceMeters($row['lat'], $row['lng'], (float) $b->latitude, (float) $b->longitude) <= $limit,
        )));

        return [$kept, count($rows) - count($kept)];
    }

    /** @return array{created: int, restored: int, removed: int, moved: int, guarded: ?string} */
    private function reconcile(string $kind, string $source, array $rows): array
    {
        return DB::transaction(function () use ($kind, $source, $rows) {
            $existing = TrashBin::where('source', $source)->get();
            $byExternalId = $existing->keyBy('external_id');
            $sameBinMeters = (float) config('petmin.open_data.same_bin_meters');
            $matched = [];
            $stats = ['created' => 0, 'restored' => 0, 'removed' => 0, 'moved' => 0, 'guarded' => null];
            $now = now();

            foreach ($rows as $row) {
                $bin = $byExternalId->get($row['external_id']);
                if ($bin === null || isset($matched[$bin->id])) {
                    // 環保局微調座標或改寫識別碼：同地址且很近的未配對桶視為同一個
                    $bin = $existing->first(fn (TrashBin $b) => ! isset($matched[$b->id]) && $b->address === $row['address']
                        && Geo::distanceMeters($row['lat'], $row['lng'], (float) $b->latitude, (float) $b->longitude) <= $sameBinMeters);
                }

                $attrs = [
                    'external_id' => $row['external_id'], 'source' => $source, 'kind' => $kind,
                    'district' => $row['district'], 'address' => $row['address'], 'note' => $row['note'],
                    'latitude' => $row['lat'], 'longitude' => $row['lng'], 'box_count' => $row['box_count'],
                    'last_seen_in_source_at' => $now,
                ];
                if ($bin === null) {
                    TrashBin::create($attrs + ['status' => TrashBin::STATUS_ACTIVE]);
                    $stats['created']++;

                    continue;
                }

                $matched[$bin->id] = true;
                if ((float) $bin->latitude !== (float) $row['lat'] || (float) $bin->longitude !== (float) $row['lng']) {
                    $stats['moved']++;
                }
                // 官方撤除後又回到清單：恢復使用中（民眾回報確認的消失不動——那筆沒有 removed_from_source_at）
                if ($bin->removed_from_source_at !== null) {
                    $attrs += ['status' => TrashBin::STATUS_ACTIVE, 'removed_from_source_at' => null];
                    $stats['restored']++;
                }
                $bin->update($attrs);
            }

            $gone = $existing->filter(fn (TrashBin $b) => ! isset($matched[$b->id]) && $b->removed_from_source_at === null);
            $allowed = max((int) config('petmin.open_data.removal_guard_min'), (int) floor($existing->count() * (float) config('petmin.open_data.removal_guard_ratio')));
            if ($gone->count() > $allowed) {
                $stats['guarded'] = "清單少了 {$gone->count()}/{$existing->count()} 筆，疑似 API 回傳殘缺，不套用撤除";

                return $stats;
            }
            foreach ($gone as $bin) {
                $bin->update(['status' => TrashBin::STATUS_MISSING, 'removed_from_source_at' => $now]);
                $stats['removed']++;
            }

            return $stats;
        });
    }
}
