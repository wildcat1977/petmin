<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        $category = $this->faker->randomElement(Report::CATEGORIES);

        return [
            'user_id' => User::factory(),
            'category' => $category,
            'water_depth_cm' => $category === Report::CATEGORY_FLOOD
                ? $this->faker->optional()->randomElement([10, 30, 50, 100, 150])
                : null,
            // 台北市範圍內隨機座標；geohash7 由 model creating hook 推導
            'latitude' => $this->faker->latitude(24.96, 25.21),
            'longitude' => $this->faker->longitude(121.45, 121.67),
            'address' => $this->faker->address(),
            'image_path' => null,
            'description' => $this->faker->optional()->sentence(),
            'status' => Report::STATUS_PENDING,
            'verification_level' => Report::VERIFICATION_UNVERIFIED,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => Report::STATUS_APPROVED]);
    }
}
