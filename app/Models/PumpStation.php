<?php

namespace App\Models;

use Database\Factories\PumpStationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PumpStation extends Model
{
    /** @use HasFactory<PumpStationFactory> */
    use HasFactory;

    public const STATUS_RUNNING = '運轉中';

    protected $fillable = [
        'stn_id', 'name', 'district', 'latitude', 'longitude', 'pump_num', 'gate_num',
        'pumb_status', 'door_status', 'inner_value', 'outer_value', 'max_level', 'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'inner_value' => 'float',
            'outer_value' => 'float',
            'max_level' => 'float',
            'observed_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return $this->pumb_status === self::STATUS_RUNNING;
    }
}
