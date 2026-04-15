<?php

namespace Database\Factories;

use App\Models\LookupUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupUnit>
 */
class LookupUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['each', 'box', 'case', 'pack', 'pair', 'kg', 'g', 'litre', 'ml', 'metre', 'hour', 'day', 'service']),
        ];
    }
}
