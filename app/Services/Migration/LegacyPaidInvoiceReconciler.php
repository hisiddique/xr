<?php

namespace App\Services\Migration;

use App\DocumentType;
use App\Models\Setting;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-reconciles legacy customer invoices confirmed paid in the old system but
 * carrying a residual balance here. Two paths per invoice: Path A (nothing yet
 * allocated) gets a synthetic cash payment plus a matching allocation for the
 * residual; Path B (partly allocated already) gets a write-off for the remainder,
 * since inventing a second payment on top of real allocations would double-count.
 * Invoices with a live write-off are left alone. Every row it writes is tagged
 * with the batch id so the run is fully reversible via revert().
 */
class LegacyPaidInvoiceReconciler
{
    private const string TAG_TEMPLATE = '[LEGACY-RECON %s] Confirmed from legacy system that these invoices are PAID';

    public function __construct(
        private ?int $excludeCustomerId,
        private ?int $onlyCustomerId,
        private int $userId,
        private string $asOf,
        private int $chunkSize = 2000,
    ) {}

    private function candidateQuery(int $cursor): Builder
    {
        return DB::table('documents as d')
            ->where('d.type', DocumentType::Invoice->value)
            ->whereNull('d.deleted_at')
            ->whereDate('d.doc_date', '<=', $this->asOf)
            ->when($this->excludeCustomerId, fn ($q) => $q->where('d.customer_id', '!=', $this->excludeCustomerId))
            ->when($this->onlyCustomerId, fn ($q) => $q->where('d.customer_id', $this->onlyCustomerId))
            ->whereNotExists(fn ($q) => $q->from('write_offs as w')
                ->whereColumn('w.document_id', 'd.id')->whereNull('w.deleted_at')->selectRaw('1'))
            ->selectRaw('d.id, d.customer_id, d.doc_number, d.total_value, d.doc_date')
            ->selectSub(
                DB::table('payment_allocations')->whereColumn('document_id', 'd.id')
                    ->whereNull('deleted_at')->selectRaw('COALESCE(SUM(allocated_amount), 0)'),
                'allocated'
            )
            ->selectSub(
                DB::table('credit_allocations')->whereColumn('invoice_id', 'd.id')
                    ->whereNull('deleted_at')->selectRaw('COALESCE(SUM(amount), 0)'),
                'credited'
            )
            ->where('d.id', '>', $cursor)
            ->orderBy('d.id')
            ->limit($this->chunkSize);
    }

    /**
     * @return array{
     *     path_a_count: int, path_a_total: float, path_a_samples: array<int, string>,
     *     path_b_count: int, path_b_total: float, path_b_samples: array<int, string>,
     *     skipped_settled: int,
     *     skipped_write_off_present: int,
     * }
     */
    public function previewCounts(): array
    {
        $pathACount = 0;
        $pathATotal = 0.0;
        $pathASamples = [];
        $pathBCount = 0;
        $pathBTotal = 0.0;
        $pathBSamples = [];
        $skippedSettled = 0;

        $cursor = 0;

        while (true) {
            $rows = $this->candidateQuery($cursor)->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $residual = round((float) $row->total_value - (float) $row->allocated - (float) $row->credited, 2);

                if ($residual <= 0.01) {
                    $skippedSettled++;

                    continue;
                }

                if ((float) $row->allocated <= 0.001) {
                    $pathACount++;
                    $pathATotal = round($pathATotal + $residual, 2);
                    if (count($pathASamples) < 10) {
                        $pathASamples[] = $row->doc_number;
                    }
                } else {
                    $pathBCount++;
                    $pathBTotal = round($pathBTotal + $residual, 2);
                    if (count($pathBSamples) < 10) {
                        $pathBSamples[] = $row->doc_number;
                    }
                }
            }

            $cursor = (int) $rows->last()->id;

            if ($rows->count() < $this->chunkSize) {
                break;
            }
        }

        $skippedWriteOffPresent = (int) DB::table('documents as d')
            ->where('d.type', DocumentType::Invoice->value)
            ->whereNull('d.deleted_at')
            ->whereDate('d.doc_date', '<=', $this->asOf)
            ->when($this->excludeCustomerId, fn ($q) => $q->where('d.customer_id', '!=', $this->excludeCustomerId))
            ->when($this->onlyCustomerId, fn ($q) => $q->where('d.customer_id', $this->onlyCustomerId))
            ->whereExists(fn ($q) => $q->from('write_offs as w')
                ->whereColumn('w.document_id', 'd.id')->whereNull('w.deleted_at')->selectRaw('1'))
            ->count();

        return [
            'path_a_count' => $pathACount,
            'path_a_total' => $pathATotal,
            'path_a_samples' => $pathASamples,
            'path_b_count' => $pathBCount,
            'path_b_total' => $pathBTotal,
            'path_b_samples' => $pathBSamples,
            'skipped_settled' => $skippedSettled,
            'skipped_write_off_present' => $skippedWriteOffPresent,
        ];
    }

    /**
     * @return array{
     *     batch: string,
     *     path_a_count: int, path_a_total: float, path_a_samples: array<int, string>,
     *     path_b_count: int, path_b_total: float, path_b_samples: array<int, string>,
     *     skipped_settled: int,
     * }
     */
    public function run(string $batch, bool $commit): array
    {
        $prefix = (string) Setting::get('pay_prefix', 'PAY');
        $padding = (int) Setting::get('number_padding', 4);

        $last = DB::table('payments')->orderByDesc('id')->value('reference');
        $seq = $last ? (int) Str::afterLast($last, '-') + 1 : 1;

        $pathACount = 0;
        $pathATotal = 0.0;
        $pathASamples = [];
        $pathBCount = 0;
        $pathBTotal = 0.0;
        $pathBSamples = [];
        $skippedSettled = 0;

        $cursor = 0;

        while (true) {
            $rows = $this->candidateQuery($cursor)->get();

            if ($rows->isEmpty()) {
                break;
            }

            try {
                DB::transaction(function () use ($rows, $batch, $prefix, $padding, $commit, &$seq) {
                    $paymentRows = [];
                    $pendingAllocations = [];
                    $pathAInvoiceIds = [];
                    $writeOffRows = [];

                    foreach ($rows as $row) {
                        $residual = round((float) $row->total_value - (float) $row->allocated - (float) $row->credited, 2);

                        if ($residual <= 0.01) {
                            continue;
                        }

                        $ts = $row->doc_date;

                        if ((float) $row->allocated <= 0.001) {
                            $reference = sprintf('%s-%s', $prefix, str_pad((string) $seq, $padding, '0', STR_PAD_LEFT));
                            $seq++;

                            $paymentRows[] = [
                                'customer_id' => $row->customer_id,
                                'payment_method_id' => null,
                                'source_type' => 'cash',
                                'reference' => $reference,
                                'payment_reference' => null,
                                'amount' => $residual,
                                'is_exhausted' => false,
                                'payment_date' => $ts,
                                'notes' => sprintf(self::TAG_TEMPLATE, $batch),
                                'reconciliation_batch' => $batch,
                                'created_by' => $this->userId,
                                'created_at' => $ts,
                                'updated_at' => $ts,
                            ];

                            $pendingAllocations[$reference] = [
                                'document_id' => $row->id,
                                'allocated_amount' => $residual,
                                'ts' => $ts,
                            ];

                            $pathAInvoiceIds[] = $row->id;
                        } else {
                            $writeOffRows[] = [
                                'document_id' => $row->id,
                                'amount' => $residual,
                                'reason' => sprintf(self::TAG_TEMPLATE, $batch),
                                'written_off_at' => $ts,
                                'written_off_by' => $this->userId,
                                'legacy_uid' => null,
                                'created_at' => $ts,
                                'updated_at' => $ts,
                            ];
                        }
                    }

                    if ($pathAInvoiceIds !== []) {
                        DB::table('payment_allocations')
                            ->whereIn('document_id', $pathAInvoiceIds)
                            ->whereNotNull('deleted_at')
                            ->delete();
                    }

                    foreach (array_chunk($paymentRows, 1000) as $c) {
                        DB::table('payments')->insert($c);
                    }

                    if ($pendingAllocations !== []) {
                        $idByRef = DB::table('payments')
                            ->where('reconciliation_batch', $batch)
                            ->whereIn('reference', array_keys($pendingAllocations))
                            ->pluck('id', 'reference');

                        $allocationRows = [];

                        foreach ($pendingAllocations as $ref => $entry) {
                            $allocationRows[] = [
                                'payment_id' => $idByRef[$ref],
                                'document_id' => $entry['document_id'],
                                'allocated_amount' => $entry['allocated_amount'],
                                'created_at' => $entry['ts'],
                                'updated_at' => $entry['ts'],
                            ];
                        }

                        foreach (array_chunk($allocationRows, 1000) as $c) {
                            DB::table('payment_allocations')->insert($c);
                        }
                    }

                    foreach (array_chunk($writeOffRows, 1000) as $c) {
                        DB::table('write_offs')->insert($c);
                    }

                    if (! $commit) {
                        throw new DryRunRollback;
                    }
                });
            } catch (DryRunRollback) {
                // Expected on a dry run — the chunk is rolled back, the loop continues.
            }

            foreach ($rows as $row) {
                $residual = round((float) $row->total_value - (float) $row->allocated - (float) $row->credited, 2);

                if ($residual <= 0.01) {
                    $skippedSettled++;

                    continue;
                }

                if ((float) $row->allocated <= 0.001) {
                    $pathACount++;
                    $pathATotal = round($pathATotal + $residual, 2);
                    if (count($pathASamples) < 10) {
                        $pathASamples[] = $row->doc_number;
                    }
                } else {
                    $pathBCount++;
                    $pathBTotal = round($pathBTotal + $residual, 2);
                    if (count($pathBSamples) < 10) {
                        $pathBSamples[] = $row->doc_number;
                    }
                }
            }

            $cursor = (int) $rows->last()->id;

            if ($rows->count() < $this->chunkSize) {
                break;
            }
        }

        return [
            'batch' => $batch,
            'path_a_count' => $pathACount,
            'path_a_total' => $pathATotal,
            'path_a_samples' => $pathASamples,
            'path_b_count' => $pathBCount,
            'path_b_total' => $pathBTotal,
            'path_b_samples' => $pathBSamples,
            'skipped_settled' => $skippedSettled,
        ];
    }

    /**
     * @return array{payments: int, allocations: int, write_offs: int}
     */
    public function revert(string $batch): array
    {
        return DB::transaction(function () use ($batch) {
            $paymentIds = DB::table('payments')
                ->where('reconciliation_batch', $batch)
                ->whereNull('deleted_at')
                ->pluck('id');

            $allocationsDeleted = 0;

            foreach ($paymentIds->chunk(1000) as $chunk) {
                $allocationsDeleted += DB::table('payment_allocations')
                    ->whereIn('payment_id', $chunk->all())
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }

            foreach ($paymentIds->chunk(1000) as $chunk) {
                DB::table('payments')
                    ->whereIn('id', $chunk->all())
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }

            $writeOffsDeleted = DB::table('write_offs')
                ->where('reason', 'like', sprintf('[LEGACY-RECON %s]', $batch).'%')
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return [
                'payments' => $paymentIds->count(),
                'allocations' => $allocationsDeleted,
                'write_offs' => $writeOffsDeleted,
            ];
        });
    }
}

final class DryRunRollback extends \RuntimeException {}
