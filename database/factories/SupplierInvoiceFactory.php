<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\User;
use App\SupplierInvoiceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'invoice_date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'due_date' => null,
            'status' => SupplierInvoiceStatus::Posted,
            'notes' => $this->faker->optional()->sentence(),
            'attachments' => null,
            'created_by' => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => SupplierInvoiceStatus::Draft]);
    }
}
