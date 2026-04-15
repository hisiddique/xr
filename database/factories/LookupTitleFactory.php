<?php

namespace Database\Factories;

use App\Models\LookupTitle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupTitle>
 */
class LookupTitleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof']),
        ];
    }
}
