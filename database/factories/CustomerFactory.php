<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_name' => fake()->company(),
            'reference' => fake()->unique()->bothify('REF-####'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'address_1' => fake()->streetAddress(),
            'town' => fake()->city(),
            'post_code' => fake()->postcode(),
            'email_1' => fake()->companyEmail(),
            'trade_discount' => fake()->randomElement([0, 5, 10, 15, 20]),
        ];
    }
}
