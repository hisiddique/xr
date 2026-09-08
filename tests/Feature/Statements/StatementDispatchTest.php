<?php

use App\Jobs\ProcessStatementDispatchRunJob;
use App\Mail\CustomerStatementMail;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Document;
use App\Models\StatementDispatchRun;
use App\Models\StatementSchedule;
use App\Services\CustomerStatementService;
use App\Services\StatementDispatchService;
use App\StatementFrequency;
use App\StatementRunItemStatus;
use App\StatementRunStatus;
use App\StatementRunTrigger;
use App\StatementScheduleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function lastMonthInvoice(Customer $customer): Document
{
    return Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3),
        'total_value' => 250,
        'is_settled' => false,
    ]);
}

test('statements:dispatch claims a due schedule, advances next_run_at and queues the job', function () {
    Queue::fake();

    $schedule = StatementSchedule::factory()->due()->create([
        'day_of_month' => 1,
        'run_time' => '09:00',
    ]);
    $originalNextRun = $schedule->next_run_at;

    $this->artisan('statements:dispatch')->assertOk();

    $run = StatementDispatchRun::where('statement_schedule_id', $schedule->id)->sole();
    expect($run->trigger)->toBe(StatementRunTrigger::Scheduled)
        ->and($run->status)->toBe(StatementRunStatus::Queued)
        ->and($run->scheduled_for->equalTo($originalNextRun))->toBeTrue();

    $schedule->refresh();
    expect($schedule->next_run_at->greaterThan(now()))->toBeTrue()
        ->and($schedule->last_run_at)->not->toBeNull();

    Queue::assertPushed(ProcessStatementDispatchRunJob::class, 1);
});

test('a second dispatch tick in the same window does not create a duplicate run', function () {
    Queue::fake();
    $schedule = StatementSchedule::factory()->due()->create(['day_of_month' => 1]);

    $this->artisan('statements:dispatch');
    // Force it due again at the same scheduled_for
    $schedule->update(['next_run_at' => $schedule->getOriginal('next_run_at')]);
    $this->artisan('statements:dispatch');

    expect(StatementDispatchRun::where('statement_schedule_id', $schedule->id)->count())->toBe(1);
});

test('draft and paused schedules are never picked up', function () {
    Queue::fake();
    StatementSchedule::factory()->draft()->create();
    StatementSchedule::factory()->paused()->create(['next_run_at' => now()->subMinute()]);

    $this->artisan('statements:dispatch');

    expect(StatementDispatchRun::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the job sends to members with activity and skips the rest', function () {
    Mail::fake();

    $group = CustomerGroup::factory()->create();
    $withActivity = Customer::factory()->create(['email_1' => 'has@x.test']);
    lastMonthInvoice($withActivity);
    $noEmail = Customer::factory()->create(['email_1' => null]);
    lastMonthInvoice($noEmail);
    $noActivity = Customer::factory()->create(['email_1' => 'quiet@x.test']);
    $group->customers()->attach([$withActivity->id, $noEmail->id, $noActivity->id]);

    $schedule = StatementSchedule::factory()->due()->create(['customer_group_id' => $group->id]);

    $this->artisan('statements:dispatch');
    $run = StatementDispatchRun::sole();
    (new ProcessStatementDispatchRunJob($run->id))->handle(app(StatementDispatchService::class));

    $run->refresh();
    expect($run->status)->toBe(StatementRunStatus::Completed)
        ->and($run->sent_count)->toBe(1)
        ->and($run->skipped_count)->toBe(2)
        ->and($run->failed_count)->toBe(0);

    expect($run->items()->where('status', StatementRunItemStatus::Skipped)->pluck('skip_reason')->sort()->values()->all())
        ->toBe(['no_activity', 'no_email']);

    Mail::assertSent(CustomerStatementMail::class, 1);
});

test('a send failure is isolated and marks the run completed_with_errors, then retry re-runs only the failure', function () {
    $group = CustomerGroup::factory()->create();
    $a = Customer::factory()->create(['email_1' => 'a@x.test']);
    $b = Customer::factory()->create(['email_1' => 'b@x.test']);
    lastMonthInvoice($a);
    lastMonthInvoice($b);
    $group->customers()->attach([$a->id, $b->id]);

    // Real service, but sendStatementEmail throws for customer $b only.
    $spy = Mockery::mock(CustomerStatementService::class.'[sendStatementEmail]', [])->makePartial();
    $spy->shouldReceive('sendStatementEmail')
        ->andReturnUsing(function (Customer $customer) use ($b) {
            if ($customer->id === $b->id) {
                throw new RuntimeException('smtp down');
            }
        });
    app()->instance(CustomerStatementService::class, $spy);

    $schedule = StatementSchedule::factory()->due()->create(['customer_group_id' => $group->id]);
    $this->artisan('statements:dispatch');
    $run = StatementDispatchRun::sole();
    (new ProcessStatementDispatchRunJob($run->id))->handle(app(StatementDispatchService::class));

    $run->refresh();
    expect($run->status)->toBe(StatementRunStatus::CompletedWithErrors)
        ->and($run->sent_count)->toBe(1)
        ->and($run->failed_count)->toBe(1);

    $failedItem = $run->items()->where('status', StatementRunItemStatus::Failed)->sole();
    expect($failedItem->customer_id)->toBe($b->id)
        ->and($failedItem->error_message)->toContain('smtp down');

    $retry = app(StatementDispatchService::class)->createRetryRun($run);
    expect($retry->trigger)->toBe(StatementRunTrigger::Retry)
        ->and($retry->parent_run_id)->toBe($run->id)
        ->and($retry->total_count)->toBe(1)
        ->and($retry->items()->sole()->customer_id)->toBe($b->id);
});

test('run now creates a manual run without touching next_run_at', function () {
    $schedule = StatementSchedule::factory()->create(['next_run_at' => now()->addWeek()]);
    $before = $schedule->next_run_at;

    $run = app(StatementDispatchService::class)->createManualRun($schedule, userId: null);

    expect($run->trigger)->toBe(StatementRunTrigger::Manual)
        ->and($run->scheduled_for)->toBeNull()
        ->and($schedule->fresh()->next_run_at->equalTo($before))->toBeTrue();
});

test('a one-time schedule is marked completed after it fires', function () {
    Queue::fake();

    $schedule = StatementSchedule::factory()->due()->create([
        'frequency' => StatementFrequency::OneTime,
        'day_of_month' => null,
        'anchor_date' => now()->subDay()->toDateString(),
        'run_time' => '09:00',
    ]);

    $this->artisan('statements:dispatch')->assertOk();

    $schedule->refresh();
    expect($schedule->status)->toBe(StatementScheduleStatus::Completed)
        ->and($schedule->next_run_at)->toBeNull()
        ->and($schedule->last_run_at)->not->toBeNull();

    // and it is not picked up again
    $schedule->update(['next_run_at' => now()->subMinute()]); // even if forced, status guards it
    $this->artisan('statements:dispatch');
    expect(StatementDispatchRun::where('statement_schedule_id', $schedule->id)->count())->toBe(1);
});

test('a recurring schedule stays active and reschedules after it fires', function () {
    Queue::fake();

    $schedule = StatementSchedule::factory()->due()->create([
        'frequency' => StatementFrequency::Weekly,
        'day_of_month' => null,
        'day_of_week' => 2,
        'run_time' => '09:00',
    ]);

    $this->artisan('statements:dispatch');

    $schedule->refresh();
    expect($schedule->status)->toBe(StatementScheduleStatus::Active)
        ->and($schedule->next_run_at)->not->toBeNull()
        ->and($schedule->next_run_at->isFuture())->toBeTrue();
});
