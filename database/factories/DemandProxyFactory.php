<?php

namespace Database\Factories;

use App\Models\DemandProxy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DemandProxy> */
class DemandProxyFactory extends Factory
{
    protected $model = DemandProxy::class;

    public function definition(): array
    {
        return [
            'kind' => DemandProxy::KIND_BUS_STOP,
            'external_id' => (string) $this->faker->unique()->numberBetween(1, 100000),
            'name' => $this->faker->streetName().'站',
            'latitude' => $this->faker->latitude(24.96, 25.21),
            'longitude' => $this->faker->longitude(121.45, 121.67),
            'weight' => 1,
        ];
    }
}
