<?php

namespace Database\Factories;

use App\Models\PromoCard;
use Illuminate\Database\Eloquent\Factories\Factory;

class PromoCardFactory extends Factory
{
    protected $model = PromoCard::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->words(2, true),
            'image_path' => 'cards/'.$this->faker->uuid().'.png',
            'group' => $this->faker->randomElement(array_keys(PromoCard::GROUP_LABELS)),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
