<?php

namespace Database\Factories;

use App\Models\PumpStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PumpStation> */
class PumpStationFactory extends Factory
{
    protected $model = PumpStation::class;

    public function definition(): array
    {
        return [
            'stn_id' => (string) fake()->unique()->numberBetween(100, 999),
            'name' => fake()->word(),
            'latitude' => fake()->randomFloat(7, 25.0, 25.15),
            'longitude' => fake()->randomFloat(7, 121.45, 121.65),
            'pump_num' => fake()->numberBetween(1, 8),
            'gate_num' => fake()->numberBetween(0, 4),
            'pumb_status' => fake()->randomElement(['停止', '運轉中']),
            'door_status' => fake()->randomElement(['閘門開啟', '閘門關閉', null]),
            'inner_value' => fake()->randomFloat(2, -1, 3),
            'outer_value' => fake()->randomFloat(2, -1, 3),
            'max_level' => fake()->randomFloat(2, 2, 5),
            'observed_at' => now(),
        ];
    }
}
