<?php

namespace Database\Factories;

use App\Models\TrashBin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TrashBin> */
class TrashBinFactory extends Factory
{
    protected $model = TrashBin::class;

    public function definition(): array
    {
        return [
            'external_id' => 'cb-'.$this->faker->unique()->numberBetween(1, 100000),
            'source' => TrashBin::SOURCE_OFFICIAL_CLEAN_BOX,
            'kind' => TrashBin::KIND_CLEAN_BOX,
            'district' => $this->faker->randomElement(['中正區', '大安區', '信義區', '士林區', '內湖區']),
            'address' => $this->faker->streetName(),
            'latitude' => $this->faker->latitude(24.96, 25.21),
            'longitude' => $this->faker->longitude(121.45, 121.67),
            'status' => TrashBin::STATUS_ACTIVE,
            'box_count' => 1,
        ];
    }

    public function dogBagBox(): static
    {
        return $this->state(fn () => [
            'source' => TrashBin::SOURCE_OFFICIAL_DOG_BAG_BOX,
            'kind' => TrashBin::KIND_DOG_BAG_BOX,
        ]);
    }

    public function missing(): static
    {
        return $this->state(fn () => ['status' => TrashBin::STATUS_MISSING]);
    }
}
