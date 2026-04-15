<?php

namespace Database\Factories;

use App\Models\DocumentEmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentEmailLog>
 */
class DocumentEmailLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_email' => fake()->safeEmail(),
            'sent_at' => now(),
            'status' => 'sent',
        ];
    }
}
