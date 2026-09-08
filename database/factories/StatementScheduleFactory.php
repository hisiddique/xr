<?php

namespace Database\Factories;

use App\Models\StatementSchedule;
use App\StatementFrequency;
use App\StatementScheduleStatus;
use App\StatementSubjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatementSchedule>
 */
class StatementScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'model_type' => StatementSubjectType::Customer,
            'status' => StatementScheduleStatus::Active,
            'frequency' => StatementFrequency::Monthly,
            'run_time' => '09:00',
            'day_of_month' => 1,
            'day_of_week' => null,
            'anchor_date' => null,
            'rules' => [
                'preset' => 'last_month',
                'outstanding_only' => true,
                'include_invoices' => true,
                'include_credit_notes' => false,
                'include_write_offs' => false,
                'include_payments' => false,
                'payment_methods' => [],
            ],
            'notify_enabled' => false,
            'notify_emails' => [],
            'next_run_at' => now()->addDay(),
        ];
    }

    public function supplier(): static
    {
        return $this->state([
            'model_type' => StatementSubjectType::Supplier,
            'rules' => [
                'preset' => 'last_month',
                'outstanding_only' => true,
                'include_invoices' => true,
                'include_debit_notes' => false,
                'include_payouts' => false,
            ],
        ]);
    }

    public function draft(): static
    {
        return $this->state(['status' => StatementScheduleStatus::Draft, 'next_run_at' => null]);
    }

    public function paused(): static
    {
        return $this->state(['status' => StatementScheduleStatus::Paused]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => StatementScheduleStatus::Completed,
            'frequency' => StatementFrequency::OneTime,
            'day_of_month' => null,
            'anchor_date' => now()->subDay()->toDateString(),
            'next_run_at' => null,
            'last_run_at' => now()->subDay(),
        ]);
    }

    public function due(): static
    {
        return $this->state([
            'status' => StatementScheduleStatus::Active,
            'next_run_at' => now()->subMinute(),
        ]);
    }
}
