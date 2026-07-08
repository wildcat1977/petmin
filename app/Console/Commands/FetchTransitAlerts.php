<?php

namespace App\Console\Commands;

use App\Models\TransitAlert;
use App\Services\DataSourceHealth;
use App\Services\TdxClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TDX 交通警示（spec §11.5 P2 Phase 2，每 5 分鐘，credential-gated）。
 * 捷運/纜車正常時回單一 sentinel AlertID "0" / Status 1；公車只收災害相關（Cause=6 或 Effect∈{4,7}）。
 */
class FetchTransitAlerts extends Command
{
    private const WEATHER_CAUSE = 6;

    private const SUSPEND_EFFECTS = [4, 7];

    protected $signature = 'hazdar:fetch-transit-alerts';

    protected $description = '拉取 TDX 捷運/纜車/公車警示並落地 transit_alerts';

    public function handle(DataSourceHealth $health, TdxClient $tdx): int
    {
        if (! $tdx->configured()) {
            $this->info('TDX 憑證未設定，略過交通警示（不影響其他源）');

            return self::SUCCESS;
        }

        $config = config('hazdar.sources.transit');

        try {
            $rows = [];
            foreach (['metro' => TransitAlert::SYSTEM_METRO, 'gondola' => TransitAlert::SYSTEM_GONDOLA, 'bus' => TransitAlert::SYSTEM_BUS] as $key => $system) {
                $data = $tdx->get($config['endpoints'][$key]);
                if ($data === null) {
                    throw new \RuntimeException("TDX {$key} 抓取失敗");
                }
                foreach ($this->mapAlerts($system, data_get($data, 'Alerts', [])) as $row) {
                    $rows[] = $row;
                }
            }

            // 全量快照：清掉舊的再重灌（本源獨立 table）；
            // 包在單一 transaction 讓讀者在提交前一直看到舊快照，避免災時瞬間讀到空表。
            DB::transaction(function () use ($rows) {
                TransitAlert::query()->delete();
                foreach ($rows as $row) {
                    TransitAlert::create($row);
                }
            });

            $health->recordSuccess('transit');
            $this->info('Transit alerts: '.count($rows).' rows');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('hazdar: transit fetch failed', ['error' => $e->getMessage()]);
            $health->recordFailure('transit', $e->getMessage());
            $this->error("Transit fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $alerts
     * @return list<array<string,mixed>>
     */
    private function mapAlerts(string $system, array $alerts): array
    {
        // 捷運/纜車：正常 sentinel（AlertID "0" 或 Status 1）→ 存一筆 status=1 代表全線正常。
        // 只有在整批完全沒有異常時才可收斂為 sentinel；任何異常都必須保留，不可被稍後出現的
        // sentinel 蓋掉（disaster feed 寧可多報，不可漏報真實中斷）。
        if ($system !== TransitAlert::SYSTEM_BUS) {
            return $this->mapRailAlerts($system, $alerts);
        }

        $out = [];
        foreach ($alerts as $a) {
            // Status 缺值 fail-safe：災害饋送寧可誤判異常（2），不可漏報為正常（1）
            $status = array_key_exists('Status', $a) ? (int) $a['Status'] : 2;
            $cause = isset($a['Cause']) ? (int) $a['Cause'] : null;
            $effect = isset($a['Effect']) ? (int) $a['Effect'] : null;

            // 公車：只收災害相關（氣候 或 停駛/重大延遲），其餘例行 alert 丟棄
            if ($cause === self::WEATHER_CAUSE || in_array($effect, self::SUSPEND_EFFECTS, true)) {
                $out[] = $this->row($system, $a, $status, $cause, $effect, $this->busScope($a));
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $alerts
     * @return list<array<string,mixed>>
     */
    private function mapRailAlerts(string $system, array $alerts): array
    {
        if ($alerts === []) {
            return [];
        }

        $abnormal = [];
        foreach ($alerts as $a) {
            $status = array_key_exists('Status', $a) ? (int) $a['Status'] : 2;
            $cause = isset($a['Cause']) ? (int) $a['Cause'] : null;
            $effect = isset($a['Effect']) ? (int) $a['Effect'] : null;
            $isNormal = ((string) ($a['AlertID'] ?? '')) === '0' || $status === TransitAlert::STATUS_NORMAL;

            if (! $isNormal) {
                $abnormal[] = $this->row($system, $a, $status, $cause, $effect, $this->railScope($a));
            }
        }

        if ($abnormal !== []) {
            // 有任一異常 → 只回報異常，捨棄 sentinel（不可讓正常 sentinel 蓋掉真實中斷）
            return $abnormal;
        }

        // 全部正常 → 每系統只留一筆代表
        $first = $alerts[0];
        $cause = isset($first['Cause']) ? (int) $first['Cause'] : null;
        $effect = isset($first['Effect']) ? (int) $first['Effect'] : null;

        return [$this->row($system, $first, 1, $cause, $effect, $this->railScope($first))];
    }

    private function railScope(array $a): ?string
    {
        return data_get($a, 'Scope.Lines.0.LineName.Zh_tw') ?? data_get($a, 'Scope.Lines.0.LineName');
    }

    private function busScope(array $a): ?string
    {
        return data_get($a, 'Scope.Routes.0.RouteName.Zh_tw') ?? data_get($a, 'Scope.Routes.0.RouteName');
    }

    private function row(string $system, array $a, int $status, ?int $cause, ?int $effect, ?string $scope): array
    {
        return [
            'system' => $system,
            'identifier' => (string) ($a['AlertID'] ?? ''),
            'scope_name' => $scope,
            'status' => $status,
            'cause' => $cause,
            'effect' => $effect,
            'title' => mb_substr((string) ($a['Title'] ?? ''), 0, 255) ?: null,
            'description' => (string) ($a['Description'] ?? '') ?: null,
            'effective_at' => $this->parseTime($a['StartTime'] ?? null),
            'expires_at' => $this->parseTime($a['EndTime'] ?? null),
        ];
    }

    private function parseTime(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
