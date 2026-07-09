<?php

namespace App\Console\Commands;

use App\Models\ExternalAlert;
use App\Services\DataSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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
 *
 * 韌性設計（2026-07-09，因 NCDR 依「IP 頻率」限流、連發即 429）：
 *   - 停班停課 feed 先抓、且與主 feed 各自 try/catch：安全關鍵的停課不因主 feed 失敗而漏，
 *     且其單一請求最可能落在「首發不擋」的成功窗；停課範圍在 headline 內，不需 CAP 明細。
 *   - 自我限速（throttle_us）＋對 429／連線錯誤退避重試（retry_backoff_ms），避免整輪爆量。
 *   - 涵蓋台北判定同時看 areaDesc 與 headline，明細抓取被限流時仍能正確標記停課涵蓋台北。
 *   - 選用 proxy 換出口 IP（config proxy）規避 IP 限流。
 */
class FetchNcdrAlerts extends Command
{
    private const CAP_NS = 'urn:oasis:names:tc:emergency:cap:1.1';

    protected $signature = 'hazdar:fetch-ncdr-alerts';

    protected $description = '拉取 NCDR 民生示警 CAP feed 並落地 external_alerts';

    /** 上一個對 NCDR 發出的請求時間（epoch 秒，含小數），供自我限速用。 */
    private ?float $lastRequestAt = null;

    public function handle(DataSourceHealth $health): int
    {
        $config = config('hazdar.sources.ncdr_alerts');
        $detailBudget = (int) $config['max_detail_fetches'];

        $created = 0;
        $errors = [];

        // 停課 feed 先抓（安全關鍵、不需明細、單一請求最易成功）；主 feed 後抓。
        // 兩者各自 try/catch：一者被 429 擋掉不連坐另一者，已落地的資料保留。
        $feeds = [
            ['url' => $config['closure_feed_url'] ?? null, 'type' => '停班停課', 'skipDetail' => true],
            ['url' => $config['feed_url'] ?? null, 'type' => null, 'skipDetail' => false],
        ];

        foreach ($feeds as $feed) {
            if (empty($feed['url'])) {
                continue;
            }

            try {
                $created += $this->ingestFeed($feed['url'], $feed['type'], $detailBudget, $feed['skipDetail']);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                Log::error('hazdar: NCDR feed fetch failed', ['url' => $feed['url'], 'error' => $e->getMessage()]);
            }
        }

        if ($errors !== []) {
            // 任一 feed 失敗即降級健康燈（驗收 §1-5：不得默默跳過），但另一 feed 已落地的資料保留。
            $message = implode('; ', $errors);
            $health->recordFailure('ncdr_alerts', $message);
            $this->error("NCDR fetch failed: {$message}");

            return self::FAILURE;
        }

        $health->recordSuccess('ncdr_alerts');
        $this->info("NCDR alerts: {$created} new");

        return self::SUCCESS;
    }

    /**
     * 抓單一 feed → 去重 → 逐筆入庫。$alertTypeOverride 非 null 時（如停班停課 feed）
     * 覆蓋 feed title 判斷出的 alert_type；$detailBudget 由呼叫端傳入並跨多次呼叫累計扣減。
     * $skipDetail=true（停課 feed）不抓 CAP 明細（範圍以 headline 判定）。
     */
    private function ingestFeed(string $url, ?string $alertTypeOverride, int &$detailBudget, bool $skipDetail = false): int
    {
        $response = $this->httpGet($url);
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

        $created = 0;

        foreach ($entries as $entry) {
            if (isset($seen[$entry['cap_id']])) {
                continue;
            }

            // 明細預算用完即停（僅限會抓明細者）：未入庫的留待下一輪（feed 新的在前，
            // 最新警戒永遠優先處理），避免以空 areas 入庫後再也不補
            if (! $skipDetail && $entry['cap_link'] && $detailBudget <= 0) {
                break;
            }
            $seen[$entry['cap_id']] = true;

            $areas = [];
            $severity = null;
            if (! $skipDetail && $entry['cap_link']) {
                $detailBudget--;
                [$areas, $severity] = $this->fetchCapDetail($entry['cap_link']);
            }

            ExternalAlert::create([
                'source' => $entry['source'],
                'cap_id' => $entry['cap_id'],
                'alert_type' => $alertTypeOverride ?? $entry['alert_type'],
                'headline' => $entry['headline'],
                'severity' => $severity,
                'areas' => $areas,
                'covers_taipei' => $this->coversTaipei($areas, $entry['headline']),
                'effective_at' => $entry['effective_at'],
                'expires_at' => $entry['expires_at'],
                'raw' => $entry,
            ]);
            $created++;
        }

        return $created;
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
            $response = $this->httpGet($url);
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
            Log::warning('hazdar: CAP detail fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return [[], null];
        }
    }

    /**
     * 對 NCDR 的 HTTP GET，統一套用：自我限速（避免連發觸發 IP 限流）、選用 proxy、
     * 以及對 429／連線錯誤的退避重試（NCDR 稍等即恢復）。
     */
    private function httpGet(string $url): Response
    {
        $config = config('hazdar.sources.ncdr_alerts');

        $throttleUs = (int) ($config['throttle_us'] ?? 0);
        if ($this->lastRequestAt !== null && $throttleUs > 0) {
            $waitedUs = (int) ((microtime(true) - $this->lastRequestAt) * 1_000_000);
            if ($waitedUs < $throttleUs) {
                usleep($throttleUs - $waitedUs);
            }
        }

        $request = Http::timeout(10);
        if (! empty($config['proxy'])) {
            $request = $request->withOptions(['proxy' => $config['proxy']]);
        }

        // 只對 429／連線錯誤退避重試；其他狀態（如 503）不重試，交由呼叫端判斷失敗。
        // throw:false → 重試耗盡後回傳最後一次 response，而非拋例外。
        $backoffMs = (int) ($config['retry_backoff_ms'] ?? 0);
        $request = $request->retry(3, $backoffMs, function (Throwable $exception): bool {
            return $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->status() === 429);
        }, throw: false);

        $this->lastRequestAt = microtime(true);

        return $request->get($url);
    }

    /**
     * 涵蓋台北市判定：areaDesc 或 headline 含「台北」/「臺北」即算（「大台北地區」也命中）。
     * headline 一併檢查，是為了讓停課 feed（不抓明細、範圍寫在 headline 如「臺北市:7/10停止上班」）
     * 或明細被限流時仍能正確標記。已知限制：以縣市逐一列舉以外的描述（如「全國」）不會命中，寧漏勿誤。
     *
     * @param  list<string>  $areas
     */
    private function coversTaipei(array $areas, string $headline = ''): bool
    {
        $haystacks = $areas;
        if ($headline !== '') {
            $haystacks[] = $headline;
        }

        foreach ($haystacks as $text) {
            if (str_contains($text, '台北') || str_contains($text, '臺北')) {
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
