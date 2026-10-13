<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\ReportCategory;
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
        $category = $this->faker->randomElement(ReportCategory::activeSlugs());

        return [
            'user_id' => User::factory(),
            'category' => $category,
            'extra_value' => $category === Report::CATEGORY_FULL
                ? $this->faker->optional()->randomElement([50, 80, 100])
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
