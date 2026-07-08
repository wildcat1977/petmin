<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransitAlert extends Model
{
    public const SYSTEM_METRO = 'metro';

    public const SYSTEM_GONDOLA = 'gondola';

    public const SYSTEM_BUS = 'bus';

    public const STATUS_NORMAL = 1;

    protected $fillable = [
        'system', 'identifier', 'scope_name', 'status', 'cause', 'effect',
        'title', 'description', 'effective_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['status' => 'integer', 'cause' => 'integer', 'effect' => 'integer',
            'effective_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
