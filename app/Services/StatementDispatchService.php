<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\StatementDispatchRun;
use App\Models\StatementDispatchRunItem;
use App\Models\StatementSchedule;
use App\Models\Supplier;
use App\StatementRunItemStatus;
use App\StatementRunStatus;
use App\StatementRunTrigger;
use App\StatementSubjectType;
use App\Support\StatementFilters;
use Carbon\CarbonInterface;

class StatementDispatchService
{
    /**
     * Queue a scheduled run for the schedule's due moment. The caller wraps this
     * in a transaction and handles the unique-constraint collision.
     */
    public function createRunForSchedule(StatementSchedule $schedule, CarbonInterface $scheduledFor): StatementDispatchRun
    {
        $period = StatementFilters::fromRules($schedule->rules ?? [], $scheduledFor)->period;

        return StatementDispatchRun::create([
            'statement_schedule_id' => $schedule->id,
            'schedule_name' => $schedule->name,
            'model_type' => $schedule->model_type,
            'trigger' => StatementRunTrigger::Scheduled,
            'status' => StatementRunStatus::Queued,
            'scheduled_for' => $scheduledFor,
            'period_from' => $period->from,
            'period_to' => $period->to,
            'period_label' => $period->label,
            'rules_snapshot' => $schedule->rules,
        ]);
    }

    /**
     * Queue a manual run for a schedule, resolving the period as of now.
     */
    public function createManualRun(StatementSchedule $schedule, ?int $userId = null): StatementDispatchRun
    {
        $period = StatementFilters::fromRules($schedule->rules ?? [], now())->period;

        return StatementDispatchRun::create([
            'statement_schedule_id' => $schedule->id,
            'schedule_name' => $schedule->name,
            'model_type' => $schedule->model_type,
            'trigger' => StatementRunTrigger::Manual,
            'status' => StatementRunStatus::Queued,
            'scheduled_for' => null,
            'period_from' => $period->from,
            'period_to' => $period->to,
            'period_label' => $period->label,
            'rules_snapshot' => $schedule->rules,
            'triggered_by' => $userId,
        ]);
    }

    /**
     * Queue a retry run that re-sends only the failed items of a parent run,
     * reusing the parent's rules snapshot verbatim.
     */
    public function createRetryRun(StatementDispatchRun $parent, ?int $userId = null): StatementDispatchRun
    {
        $run = StatementDispatchRun::create([
            'statement_schedule_id' => $parent->statement_schedule_id,
            'schedule_name' => $parent->schedule_name,
            'parent_run_id' => $parent->id,
            'model_type' => $parent->model_type,
            'trigger' => StatementRunTrigger::Retry,
            'status' => StatementRunStatus::Queued,
            'scheduled_for' => null,
            'period_from' => $parent->period_from,
            'period_to' => $parent->period_to,
            'period_label' => $parent->period_label,
            'rules_snapshot' => $parent->rules_snapshot,
            'triggered_by' => $userId,
        ]);

        foreach ($parent->items()->where('status', StatementRunItemStatus::Failed)->cursor() as $item) {
            $run->items()->create([
                'recipient_type' => $item->recipient_type,
                'customer_id' => $item->customer_id,
                'supplier_id' => $item->supplier_id,
                'recipient_name' => $item->recipient_name,
                'recipient_email' => $item->recipient_email,
                'status' => StatementRunItemStatus::Pending,
            ]);
        }

        $run->update(['total_count' => $run->items()->count()]);

        return $run;
    }

    /**
     * Expand a run into per-recipient items. Retry runs already carry their items
     * and are left untouched.
     */
    public function materializeItems(StatementDispatchRun $run): void
    {
        if ($run->items()->exists()) {
            return;
        }

        $schedule = $run->schedule;

        if ($schedule === null) {
            $run->update(['total_count' => 0]);

            return;
        }

        $isCustomer = $run->model_type === StatementSubjectType::Customer;

        if ($isCustomer) {
            $recipients = $schedule->customer_group_id
                ? $schedule->targetGroup()->customers()->get()
                : Customer::query()->get();

            if ($schedule->exclude_customer_group_id && $schedule->exclusionGroup() !== null) {
                $excludedIds = $schedule->exclusionGroup()->customers()->get()->modelKeys();
                $recipients = $recipients->reject(fn (Customer $c) => in_array($c->getKey(), $excludedIds, false));
            }
        } else {
            $recipients = $schedule->supplier_group_id
                ? $schedule->targetGroup()->suppliers()->get()
                : Supplier::query()->get();

            if ($schedule->exclude_supplier_group_id && $schedule->exclusionGroup() !== null) {
                $excludedIds = $schedule->exclusionGroup()->suppliers()->get()->modelKeys();
                $recipients = $recipients->reject(fn (Supplier $s) => in_array($s->getKey(), $excludedIds, false));
            }
        }

        $recipients = $recipients->unique(fn ($recipient) => $recipient->getKey())->values();

        foreach ($recipients as $recipient) {
            $run->items()->create([
                'recipient_type' => $run->model_type,
                'customer_id' => $isCustomer ? $recipient->getKey() : null,
                'supplier_id' => $isCustomer ? null : $recipient->getKey(),
                'recipient_name' => $recipient->company_name,
                'recipient_email' => $isCustomer ? $recipient->email_1 : $recipient->email,
                'status' => StatementRunItemStatus::Pending,
            ]);
        }

        $run->update(['total_count' => $recipients->count()]);
    }

    /**
     * Recompute item counters and move the run to its terminal status.
     */
    public function finalizeRun(StatementDispatchRun $run): void
    {
        $counts = $run->items()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $sent = (int) ($counts[StatementRunItemStatus::Sent->value] ?? 0);
        $failed = (int) ($counts[StatementRunItemStatus::Failed->value] ?? 0);
        $skipped = (int) ($counts[StatementRunItemStatus::Skipped->value] ?? 0);

        $run->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'status' => $failed > 0 ? StatementRunStatus::CompletedWithErrors : StatementRunStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    /**
     * Send one recipient's statement. Skips (without throwing) when the recipient
     * has no email, no longer exists, or has no activity for the period; lets send
     * failures propagate for the job to record as Failed.
     */
    public function processItem(StatementDispatchRunItem $item, StatementDispatchRun $run): void
    {
        $asOf = $run->scheduled_for ?? $run->created_at;

        if (blank($item->recipient_email)) {
            $item->update(['status' => StatementRunItemStatus::Skipped, 'skip_reason' => 'no_email']);

            return;
        }

        $filters = StatementFilters::fromRules($run->rules_snapshot ?? [], $asOf)->toArray();

        if ($item->recipient_type === StatementSubjectType::Customer) {
            $subject = Customer::find($item->customer_id);
            $service = app(CustomerStatementService::class);
        } else {
            $subject = Supplier::find($item->supplier_id);
            $service = app(SupplierStatementService::class);
        }

        if ($subject === null) {
            $item->update(['status' => StatementRunItemStatus::Skipped, 'skip_reason' => 'no_activity']);

            return;
        }

        $invoiceRows = $service->buildInvoiceRows($subject, $filters);

        if ($invoiceRows === []) {
            $item->update(['status' => StatementRunItemStatus::Skipped, 'skip_reason' => 'no_activity']);

            return;
        }

        $outstanding = (float) array_sum(array_column($invoiceRows, 'outstanding'));

        $service->sendStatementEmail($subject, $filters, [$item->recipient_email], null, $asOf);

        $item->update([
            'status' => StatementRunItemStatus::Sent,
            'sent_at' => now(),
            'outstanding_total' => $outstanding,
        ]);
    }
}
