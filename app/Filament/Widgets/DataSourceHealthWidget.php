<?php

namespace App\Filament\Widgets;

use App\Models\Report;
use App\Services\DataSourceHealth;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Queue;

/**
 * 後台資料源健康面板（spec §1-5 / §11.1）：承接 eoc-dashboard 舊 footer 的
 * 狀態燈 + 今日統計，讓外部資料源 fetch 失敗有地方可視。
 */
class DataSourceHealthWidget extends Widget
{
    protected string $view = 'filament.widgets.data-source-health';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -3;

    /** @var array<string, string> 資料源代碼 → 顯示標籤（沿用 eoc-dashboard 既有對照） */
    private const SOURCE_LABELS = [
        'cwa_observations' => 'CWA API',
        'ncdr_alerts' => 'NCDR API',
        'radar_echo' => '雷達回波',
        'shelters' => 'DATA.TPE',
    ];

    /**
     * @return array{sources: array<string, array{status: string, label: string, last_success_at: ?string, last_error: ?array}>, today_reports: int, official: int, queue_depth: int}
     */
    protected function getViewData(): array
    {
        return [
            'sources' => collect(app(DataSourceHealth::class)->all())
                ->map(fn (array $status, string $source) => [
                    ...$status,
                    'label' => self::SOURCE_LABELS[$source] ?? $source,
                ])->all(),
            'today_reports' => Report::whereDate('created_at', today())->count(),
            'official' => Report::where('verification_level', Report::VERIFICATION_OFFICIAL)->count(),
            'queue_depth' => $this->queueDepth(),
        ];
    }

    private function queueDepth(): int
    {
        try {
            return (int) Queue::size();
        } catch (\Throwable) {
            return 0;
        }
    }
}
