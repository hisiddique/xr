<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use App\Models\WriteOff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WriteOff>
 */
class WriteOffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_id' => Document::factory()->invoice(),
            'amount' => fake()->randomFloat(2, 10, 1000),
            'reason' => fake()->sentence(),
            'written_off_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'written_off_by' => User::factory(),
            'legacy_uid' => null,
        ];
    }
}
