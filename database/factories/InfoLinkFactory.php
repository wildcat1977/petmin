<?php

namespace Database\Factories;

use App\Models\InfoLink;
use Illuminate\Database\Eloquent\Factories\Factory;

class InfoLinkFactory extends Factory
{
    protected $model = InfoLink::class;

    public function definition(): array
    {
        return [
            'section' => $this->faker->randomElement(array_keys(InfoLink::SECTION_LABELS)),
            'label' => $this->faker->words(2, true),
            'url' => $this->faker->url(),
            'status_text' => null,
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
