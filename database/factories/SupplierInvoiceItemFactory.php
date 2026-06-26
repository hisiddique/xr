<?php

namespace Database\Factories;

use App\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierInvoiceItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = $this->faker->randomFloat(2, 1, 50);
        $unitAmount = $this->faker->randomFloat(2, 5, 500);

        return [
            'supplier_invoice_id' => SupplierInvoice::factory(),
            'product_code' => $this->faker->optional()->bothify('PROD-####'),
            'quantity' => $quantity,
            'unit_amount' => $unitAmount,
            'vat_applicable' => $this->faker->boolean(),
            'line_total' => round($quantity * $unitAmount, 2),
            'sort_order' => 0,
        ];
    }
}
