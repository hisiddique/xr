<?php

namespace Database\Factories;

use App\Models\User;
use App\SupplierCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_name' => $this->faker->company(),
            'category' => SupplierCategory::Trading->value,
            'reference' => strtoupper($this->faker->unique()->bothify('SUP-####')),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'trade_discount' => 0,
            'vat_applied' => false,
            'created_by' => User::factory(),
        ];
    }
}
