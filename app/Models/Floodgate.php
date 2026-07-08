<?php

namespace App\Models;

use Database\Factories\FloodgateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Floodgate extends Model
{
    /** @use HasFactory<FloodgateFactory> */
    use HasFactory;

    protected $fillable = ['external_key', 'name', 'description', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }
}
