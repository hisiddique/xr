<?php

namespace Database\Factories;

use App\Models\LookupPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupPaymentMethod>
 */
class LookupPaymentMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Bank Transfer', 'Cheque', 'Cash', 'Card']),
        ];
    }
}
