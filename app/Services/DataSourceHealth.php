<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 外部資料源健康狀態（spec §1-5 / §5）：
 * 任一 scheduled fetch 失敗必須記錄並反映在 dashboard footer 狀態燈，禁止默默跳過。
 *
 * 燈號規則（以該源排程週期為基準）：
 *   green  = 最近一次成功在 2× 週期內
 *   yellow = 延遲（2×～6× 週期）
 *   red    = 中斷(超過 6× 週期或從未成功)
 */
class DataSourceHealth
{
    public const STATUS_OK = 'green';

    public const STATUS_DELAYED = 'yellow';

    public const STATUS_DOWN = 'red';

    public function recordSuccess(string $source): void
    {
        Cache::forever($this->key($source, 'last_success_at'), now()->toIso8601String());
        Cache::forget($this->key($source, 'last_error'));
    }

    public function recordFailure(string $source, string $message): void
    {
        Cache::forever($this->key($source, 'last_error'), [
            'at' => now()->toIso8601String(),
            'message' => mb_substr($message, 0, 500),
        ]);
    }

    /**
     * @return array{status: string, last_success_at: ?string, last_error: ?array}
     */
    public function status(string $source): array
    {
        $intervalMinutes = (int) config("hazdar.sources.{$source}.interval_minutes", 5);
        $lastSuccess = Cache::get($this->key($source, 'last_success_at'));

        $status = self::STATUS_DOWN;
        if ($lastSuccess) {
            $age = Carbon::parse($lastSuccess)->diffInMinutes(now());
            $status = match (true) {
                $age <= $intervalMinutes * 2 => self::STATUS_OK,
                $age <= $intervalMinutes * 6 => self::STATUS_DELAYED,
                default => self::STATUS_DOWN,
            };
        }

        return [
            'status' => $status,
            'last_success_at' => $lastSuccess,
            'last_error' => Cache::get($this->key($source, 'last_error')),
        ];
    }

    /** @return array<string, array{status: string, last_success_at: ?string, last_error: ?array}> */
    public function all(): array
    {
        return collect(array_keys(config('hazdar.sources', [])))
            ->mapWithKeys(fn (string $source) => [$source => $this->status($source)])
            ->all();
    }

    private function key(string $source, string $field): string
    {
        return "hazdar:health:{$source}:{$field}";
    }
}
