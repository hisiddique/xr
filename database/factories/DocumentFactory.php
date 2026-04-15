<?php

namespace Database\Factories;

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 5000);
        $discount = fake()->randomElement([0, 5, 10]);
        $discountAmount = round($subtotal * ($discount / 100), 2);
        $afterDiscount = $subtotal - $discountAmount;
        $vatAmount = round($afterDiscount * 0.2, 2);

        return [
            'customer_id' => Customer::factory(),
            'type' => DocumentType::DeliveryNote,
            'doc_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'subtotal' => $subtotal,
            'trade_discount' => $discount,
            'discount_amount' => $discountAmount,
            'vat_amount' => $vatAmount,
            'total_value' => $afterDiscount + $vatAmount,
            'status' => DocumentStatus::Active,
        ];
    }

    public function deliveryNote(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::DeliveryNote,
        ]);
    }

    public function invoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::Invoice,
        ]);
    }
}
