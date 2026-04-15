<?php

namespace Database\Factories;

use App\Models\LookupCreditLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupCreditLimit>
 */
class LookupCreditLimitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount' => fake()->randomElement([500, 1000, 2500, 5000, 10000]),
        ];
    }
}
