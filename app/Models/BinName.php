<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 佔領命名的一次送審（見 create_bin_names_table migration 與 config petmin.bin_naming）。 */
class BinName extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => '⏳ 待審',
        self::STATUS_APPROVED => '✅ 已公開',
        self::STATUS_REJECTED => '🚫 未通過',
    ];

    public const REJECTION_REASONS = [
        'ai' => 'AI 審核未通過',
        'admin' => '管理員撤下',
    ];

    protected $fillable = [
        'trash_bin_id', 'user_id', 'name', 'status', 'rejection_reason', 'moderation_note', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function trashBin(): BelongsTo
    {
        return $this->belongsTo(TrashBin::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 對外顯示的佔領者名稱：隱私模式顯示匿名（同巡桶英雄榜） */
    public function ownerLabel(): string
    {
        return (! $this->user || $this->user->is_private) ? __('匿名巡桶員') : $this->user->name;
    }
}
