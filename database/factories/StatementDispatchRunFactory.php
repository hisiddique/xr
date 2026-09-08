<?php

namespace Database\Factories;

use App\Models\StatementDispatchRun;
use App\Models\StatementSchedule;
use App\StatementRunStatus;
use App\StatementRunTrigger;
use App\StatementSubjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatementDispatchRun>
 */
class StatementDispatchRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'statement_schedule_id' => StatementSchedule::factory(),
            'schedule_name' => fake()->words(3, true),
            'model_type' => StatementSubjectType::Customer,
            'trigger' => StatementRunTrigger::Scheduled,
            'status' => StatementRunStatus::Queued,
            'scheduled_for' => now(),
            'rules_snapshot' => [
                'preset' => 'last_month',
                'include_invoices' => true,
            ],
        ];
    }
}
