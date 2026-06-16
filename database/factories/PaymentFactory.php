<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'payment_method_id' => LookupPaymentMethod::factory(),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'payment_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
