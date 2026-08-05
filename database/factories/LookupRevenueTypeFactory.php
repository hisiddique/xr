<?php

namespace Database\Factories;

use App\Models\LookupRevenueType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupRevenueType>
 */
class LookupRevenueTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Product Sales', 'Service Revenue', 'Rental Income', 'Other Income']),
        ];
    }
}
