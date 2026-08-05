<?php

namespace Database\Factories;

use App\Models\LookupCustomerCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupCustomerCategory>
 */
class LookupCustomerCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Retail', 'Wholesale', 'Trade', 'Public Sector', 'Export']),
        ];
    }
}
