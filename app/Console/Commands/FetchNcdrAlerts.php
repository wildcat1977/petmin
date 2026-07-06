<?php

namespace App\Console\Commands;

use App\Models\ExternalAlert;
use App\Services\DataSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * NCDR 民生示警（CAP）拉取（spec §5，每 5 分鐘）。
 *
 * Atom feed（公開，無需金鑰）列出警戒摘要；新 cap_id 再抓 CAP XML 取 areaDesc，
 * ingest 時判定是否涵蓋台北市。跑馬燈只讀落地資料。
 */
class FetchNcdrAlerts extends Command
{
    private const CAP_NS = 'urn:oasis:names:tc:emergency:cap:1.1';

    protected $signature = 'civicshield:fetch-ncdr-alerts';

    protected $description = '拉取 NCDR 民生示警 CAP feed 並落地 external_alerts';

    public function handle(DataSourceHealth $health): int
    {
        $config = config('civicshield.sources.ncdr_alerts');

        try {
            $response = Http::timeout(10)->get($config['feed_url']);
            if (! $response->successful()) {
                throw new RuntimeException("feed HTTP {$response->status()}");
            }

            $entries = $this->parseFeed($response->body());

            // feed 內同一 cap_id 可能出現多次（多語系/重發），連同 DB 既有的一起去重
            $seen = ExternalAlert::query()
                ->whereIn('cap_id', array_column($entries, 'cap_id'))
                ->pluck('cap_id')
                ->flip()
                ->all();

            $detailBudget = (int) $config['max_detail_fetches'];
            $created = 0;

            foreach ($entries as $entry) {
                if (isset($seen[$entry['cap_id']])) {
                    continue;
                }

                // 明細預算用完即停：未入庫的留待下一輪（feed 新的在前，
                // 最新警戒永遠優先處理），避免以空 areas 入庫後再也不補
                if ($entry['cap_link'] && $detailBudget <= 0) {
                    break;
                }
                $seen[$entry['cap_id']] = true;

                $areas = [];
                $severity = null;
                if ($entry['cap_link']) {
                    $detailBudget--;
                    [$areas, $severity] = $this->fetchCapDetail($entry['cap_link']);
                }

                ExternalAlert::create([
                    'source' => $entry['source'],
                    'cap_id' => $entry['cap_id'],
                    'alert_type' => $entry['alert_type'],
                    'headline' => $entry['headline'],
                    'severity' => $severity,
                    'areas' => $areas,
                    'covers_taipei' => $this->coversTaipei($areas),
                    'effective_at' => $entry['effective_at'],
                    'expires_at' => $entry['expires_at'],
                    'raw' => $entry,
                ]);
                $created++;
            }

            $health->recordSuccess('ncdr_alerts');
            $this->info("NCDR alerts: {$created} new / ".count($entries).' in feed');

            return self::SUCCESS;
        } catch (Throwable $e) {
            // 失敗記 log + 健康降級；不 retry storm，下一輪 schedule 自然重試（spec §8）
            Log::error('civicshield: NCDR fetch failed', ['error' => $e->getMessage()]);
            $health->recordFailure('ncdr_alerts', $e->getMessage());
            $this->error("NCDR fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /** @return list<array{cap_id: string, alert_type: string, headline: string, source: string, effective_at: ?Carbon, expires_at: ?Carbon, cap_link: string}> */
    private function parseFeed(string $xmlBody): array
    {
        $xml = new SimpleXMLElement($xmlBody);
        $entries = [];

        foreach ($xml->entry as $entry) {
            $cap = $entry->children(self::CAP_NS);
            $title = trim((string) $entry->title);
            $summary = trim(strip_tags((string) $entry->summary));

            $entries[] = [
                'cap_id' => (string) $entry->id,
                'alert_type' => mb_substr($title, 0, 64),
                'headline' => mb_substr($summary !== '' ? $summary : $title, 0, 500),
                'source' => trim((string) ($entry->author->name ?? '')) ?: 'NCDR',
                'effective_at' => $this->parseZhDateTime((string) $cap->effective),
                'expires_at' => $this->parseZhDateTime((string) $cap->expires),
                'cap_link' => (string) ($entry->link['href'] ?? ''),
            ];
        }

        return $entries;
    }

    /**
     * 抓單筆 CAP XML 取 areaDesc 與 severity。失敗不阻塞整輪（areas 缺漏可人工複查）。
     *
     * @return array{0: list<string>, 1: ?string}
     */
    private function fetchCapDetail(string $url): array
    {
        try {
            $response = Http::timeout(10)->get($url);
            if (! $response->successful()) {
                return [[], null];
            }

            $xml = new SimpleXMLElement($response->body());
            $ns = $xml->getNamespaces(true);
            $capChildren = isset($ns['']) ? $xml->children($ns['']) : $xml;

            $areas = [];
            $severity = null;
            foreach ($capChildren->info as $info) {
                $severity ??= trim((string) $info->severity) ?: null;
                foreach ($info->area as $area) {
                    $desc = trim((string) $area->areaDesc);
                    if ($desc !== '') {
                        $areas[] = $desc;
                    }
                }
            }

            return [array_values(array_unique($areas)), $severity];
        } catch (Throwable $e) {
            Log::warning('civicshield: CAP detail fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return [[], null];
        }
    }

    /**
     * 涵蓋台北市判定：areaDesc 含「台北」或「臺北」即算（「大台北地區」也命中）。
     * 已知限制：以縣市逐一列舉以外的描述（如「全國」）不會命中，寧漏勿誤。
     */
    private function coversTaipei(array $areas): bool
    {
        foreach ($areas as $area) {
            if (str_contains($area, '台北') || str_contains($area, '臺北')) {
                return true;
            }
        }

        return false;
    }

    /** NCDR feed 的時間格式為「2026/7/6 下午 02:17:34」（Asia/Taipei） */
    private function parseZhDateTime(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $isPm = str_contains($value, '下午');
        $clean = trim(str_replace(['上午', '下午'], ' ', $value));
        $clean = preg_replace('/\s+/', ' ', $clean);

        try {
            $dt = Carbon::createFromFormat('Y/n/j G:i:s', $clean, 'Asia/Taipei');
        } catch (Throwable) {
            try {
                $dt = Carbon::parse($clean, 'Asia/Taipei');
            } catch (Throwable) {
                return null;
            }
        }

        if ($isPm && $dt->hour < 12) {
            $dt->addHours(12);
        } elseif (! $isPm && $dt->hour === 12) {
            $dt->subHours(12);
        }

        return $dt;
    }
}
