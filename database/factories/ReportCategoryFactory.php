<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ReportCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(1),
            'name' => $this->faker->word(),
            'emoji' => '📍',
            'color' => $this->faker->hexColor(),
            'sort_order' => $this->faker->numberBetween(70, 200),
            'is_active' => true,
            'extra_field' => null,
        ];
    }
}
