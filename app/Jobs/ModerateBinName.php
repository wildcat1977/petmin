<?php

namespace App\Jobs;

use App\Models\BinName;
use App\Services\BinNameModerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 佔領命名的 AI 審核（格式與黑名單已在送出時擋過）：
 *   allowed → approved（上地圖）；不允許 → rejected；AI 沒給判斷 → 維持 pending 等後台人工（fail-closed）
 */
class ModerateBinName implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $binNameId) {}

    public function handle(BinNameModerationService $service): void
    {
        $binName = BinName::find($this->binNameId);
        if (! $binName || $binName->status !== BinName::STATUS_PENDING) {
            return;
        }

        $verdict = $service->review($binName->name);
        if ($verdict === null) {
            return;
        }

        $binName->update($verdict['allowed']
            ? ['status' => BinName::STATUS_APPROVED, 'moderation_note' => $verdict['reason'], 'reviewed_at' => now()]
            : ['status' => BinName::STATUS_REJECTED, 'rejection_reason' => 'ai', 'moderation_note' => $verdict['reason'], 'reviewed_at' => now()]);
    }
}
