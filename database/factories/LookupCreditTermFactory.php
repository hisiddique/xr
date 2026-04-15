<?php

namespace Database\Factories;

use App\Models\LookupCreditTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupCreditTerm>
 */
class LookupCreditTermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Net 7 days', 'Net 14 days', 'Net 30 days', 'Net 60 days', 'Cash on Delivery']),
        ];
    }
}
