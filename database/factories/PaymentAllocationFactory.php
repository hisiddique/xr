<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'document_id' => Document::factory()->invoice(),
            'allocated_amount' => fake()->randomFloat(2, 10, 1000),
        ];
    }
}
