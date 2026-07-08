<?php

namespace Database\Factories;

use App\Models\Floodgate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Floodgate> */
class FloodgateFactory extends Factory
{
    protected $model = Floodgate::class;

    public function definition(): array
    {
        return [
            'external_key' => fake()->unique()->slug(),
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'latitude' => fake()->randomFloat(7, 24.98, 25.15),
            'longitude' => fake()->randomFloat(7, 121.45, 121.65),
        ];
    }
}
