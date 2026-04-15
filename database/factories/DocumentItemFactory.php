<?php

namespace Database\Factories;

use App\Models\DocumentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentItem>
 */
class DocumentItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = fake()->randomFloat(2, 1, 50);
        $price = fake()->randomFloat(2, 5, 200);

        return [
            'details' => fake()->sentence(4),
            'quantity' => $qty,
            'price' => $price,
            'per' => fake()->randomElement(['each', 'box', 'kg', 'litre', null]),
            'line_value' => round($qty * $price, 2),
        ];
    }
}
