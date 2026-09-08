<?php

namespace Database\Factories;

use App\Models\StatementDispatchRun;
use App\Models\StatementDispatchRunItem;
use App\StatementRunItemStatus;
use App\StatementSubjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatementDispatchRunItem>
 */
class StatementDispatchRunItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'statement_dispatch_run_id' => StatementDispatchRun::factory(),
            'recipient_type' => StatementSubjectType::Customer,
            'recipient_name' => fake()->company(),
            'recipient_email' => fake()->safeEmail(),
            'status' => StatementRunItemStatus::Pending,
        ];
    }
}
