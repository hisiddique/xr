<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\Overhead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Overhead>
 */
class OverheadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => ExpenseCategory::factory(),
            'expense_date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'has_vat' => $this->faker->boolean(),
            'payment_method' => $this->faker->randomElement(['Bank Transfer', 'Cheque', 'Cash', 'Card']),
        ];
    }
}
