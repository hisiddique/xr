<?php

namespace App\Services\Migration;

use App\MigrationRunStatus;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\MigrationRun;
use App\Models\MigrationRunTable;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\User;
use App\Services\Migration\Mappers\CustomerMapper;
use App\Services\Migration\Mappers\DocumentItemMapper;
use App\Services\Migration\Mappers\DocumentMapper;
use App\Services\Migration\Mappers\LookupUnitMapper;
use App\Services\Migration\Mappers\PaymentMapper;
use App\Services\Migration\Mappers\SettingsMapper;
use App\Services\Migration\Mappers\SupplierDebitNoteItemMapper;
use App\Services\Migration\Mappers\SupplierDebitNoteMapper;
use App\Services\Migration\Mappers\SupplierInvoiceItemMapper;
use App\Services\Migration\Mappers\SupplierInvoiceMapper;
use App\Services\Migration\Mappers\SupplierMapper;
use App\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MigrationRunner
{
    private const int CHUNK_SIZE = 25000;

    private const int UPSERT_BATCH_SIZE = 3000;

    /**
     * @var array<string, array<int, string>>
     */
    private const array GROUPS = [
        'customers' => ['customers'],
        'suppliers' => ['suppliers'],
        'documents' => ['documents'],
        'document_items' => ['document_items'],
        'payments' => ['payments'],
        'purchase_invoices' => ['supplier_invoices', 'supplier_invoice_items'],
        'purchase_credit_notes' => ['supplier_debit_notes', 'supplier_debit_note_items'],
    ];

    public function __construct(private readonly MigrationRun $run) {}

    /**
     * @param  array<int, string>  $selectedGroups  keys from self::GROUPS
     */
    public function run(array $selectedGroups, DuplicateStrategy $strategy, string $clearMode, int $createdByUserId): void
    {
        $this->run->update([
            'status' => MigrationRunStatus::Running,
            'started_at' => now(),
        ]);

        if ($clearMode !== 'none') {
            $this->ensureFallbackAdminExists();
            $this->clearData($selectedGroups, $clearMode);
        }

        $mapperKeys = $this->buildMapperKeyOrder($selectedGroups);
        $mappers = [];
        $stats = [];

        // Pre-create a Pending row (with its real rows_total already known via count())
        // for every entity in the run up front, before processing any of them — so the
        // progress page can render every entity's bar from the very first poll, instead
        // of entities silently not existing yet until the loop happens to reach them.
        foreach ($mapperKeys as $mapperKey) {
            $mapper = $this->makeMapper($mapperKey, $createdByUserId);
            $mappers[$mapperKey] = $mapper;

            $stats[$mapperKey] = MigrationRunTable::create([
                'migration_run_id' => $this->run->id,
                'entity' => $mapper->key(),
                'status' => MigrationRunStatus::Pending,
                'rows_total' => $mapper->count(),
                'rows_processed' => 0,
                'added' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'orphaned_in_legacy' => $mapper instanceof ReportsExcludedRows ? $mapper->excludedCount() : 0,
            ]);
        }

        foreach ($mapperKeys as $mapperKey) {
            $mapper = $mappers[$mapperKey];
            $stat = $stats[$mapperKey];

            if ($this->isCancelled()) {
                $this->markCancelled($stat);

                return;
            }

            $stat->update(['status' => MigrationRunStatus::Running]);

            [$failed, $cancelled] = $this->processMapper($mapper, $strategy, $stat);

            if ($cancelled) {
                $this->markCancelled($stat);

                return;
            }

            if ($failed > 0) {
                $stat->update([
                    'status' => MigrationRunStatus::Failed,
                    'error' => "{$failed} row(s) failed",
                ]);

                $this->run->update([
                    'status' => MigrationRunStatus::Failed,
                    'finished_at' => now(),
                    'error' => "Migration halted: {$failed} row(s) failed while processing {$mapperKey}.",
                ]);

                return;
            }

            $stat->update(['status' => MigrationRunStatus::Completed]);
        }

        $this->run->update([
            'status' => MigrationRunStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $selectedGroups
     * @return array<int, string>
     */
    private function buildMapperKeyOrder(array $selectedGroups): array
    {
        $mapperKeys = ['lookups', 'settings'];

        foreach (self::GROUPS as $groupKey => $groupMapperKeys) {
            if (in_array($groupKey, $selectedGroups, true)) {
                array_push($mapperKeys, ...$groupMapperKeys);
            }
        }

        return $mapperKeys;
    }

    private function makeMapper(string $mapperKey, int $createdByUserId): EntityMapper
    {
        $mapper = match ($mapperKey) {
            'lookups' => new LookupUnitMapper,
            'settings' => new SettingsMapper,
            'customers' => new CustomerMapper,
            'suppliers' => new SupplierMapper,
            'documents' => new DocumentMapper,
            'document_items' => new DocumentItemMapper,
            'payments' => new PaymentMapper,
            'supplier_invoices' => new SupplierInvoiceMapper,
            'supplier_invoice_items' => new SupplierInvoiceItemMapper,
            'supplier_debit_notes' => new SupplierDebitNoteMapper,
            'supplier_debit_note_items' => new SupplierDebitNoteItemMapper,
            default => throw new \InvalidArgumentException("Unknown mapper key: {$mapperKey}"),
        };

        if ($mapper instanceof CustomerMapper || $mapper instanceof SupplierMapper || $mapper instanceof DocumentMapper || $mapper instanceof SupplierInvoiceMapper || $mapper instanceof SupplierDebitNoteMapper || $mapper instanceof PaymentMapper) {
            $mapper->setCreatedBy($createdByUserId);
        }

        return $mapper;
    }

    /**
     * @return array{0: int, 1: bool} [failed count, whether cancellation was detected]
     */
    private function processMapper(EntityMapper $mapper, DuplicateStrategy $strategy, MigrationRunTable $stat): array
    {
        $totals = ['processed' => 0, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        $chunk = [];

        foreach ($mapper->rows(self::CHUNK_SIZE) as $row) {
            $chunk[] = $row;

            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->flushChunk($mapper, $chunk, $strategy, $stat, $totals);
                $chunk = [];

                // Cancellation is cooperative: checked between chunks (not per-row) so it
                // doesn't add a query to every row, while still being responsive — at
                // CHUNK_SIZE rows per check, the delay before a cancel takes effect is
                // bounded by how long one chunk's upsert takes, not the whole entity.
                if ($this->isCancelled()) {
                    return [$totals['failed'], true];
                }
            }
        }

        if ($chunk !== []) {
            $this->flushChunk($mapper, $chunk, $strategy, $stat, $totals);
        }

        return [$totals['failed'], false];
    }

    private function isCancelled(): bool
    {
        return MigrationRun::whereKey($this->run->getKey())->whereNotNull('cancelled_at')->exists();
    }

    private function markCancelled(MigrationRunTable $currentStat): void
    {
        $currentStat->update(['status' => MigrationRunStatus::Cancelled]);

        MigrationRunTable::where('migration_run_id', $this->run->id)
            ->where('status', MigrationRunStatus::Pending)
            ->update(['status' => MigrationRunStatus::Cancelled]);

        $this->run->update([
            'status' => MigrationRunStatus::Cancelled,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     * @param  array{processed: int, added: int, updated: int, skipped: int, failed: int}  $totals
     */
    private function flushChunk(EntityMapper $mapper, array $chunk, DuplicateStrategy $strategy, MigrationRunTable $stat, array &$totals): void
    {
        [$added, $updated, $skipped, $failed] = $this->applyChunk($mapper, $chunk, $strategy);

        $totals['processed'] += count($chunk);
        $totals['added'] += $added;
        $totals['updated'] += $updated;
        $totals['skipped'] += $skipped;
        $totals['failed'] += $failed;

        $stat->update([
            'rows_processed' => $totals['processed'],
            'added' => $totals['added'],
            'updated' => $totals['updated'],
            'skipped' => $totals['skipped'],
            'failed' => $totals['failed'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function applyChunk(EntityMapper $mapper, array $chunk, DuplicateStrategy $strategy): array
    {
        if ($mapper instanceof BulkEntityMapper) {
            return $this->applyBulkChunk($mapper, $chunk, $strategy);
        }

        return $this->applyPerRowChunk($mapper, $chunk, $strategy);
    }

    /**
     * Per-row path for the low-volume mappers (lookups, settings) whose write logic
     * doesn't fit a uniform upsert. No per-row nested transaction/savepoint: MySQL/InnoDB
     * (unlike Postgres) doesn't poison the surrounding transaction when a single statement
     * fails, so a plain try/catch around each row is enough to isolate failures without
     * paying for an extra SAVEPOINT + RELEASE round trip on every row.
     *
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function applyPerRowChunk(EntityMapper $mapper, array $chunk, DuplicateStrategy $strategy): array
    {
        $added = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        DB::transaction(function () use ($mapper, $chunk, $strategy, &$added, &$updated, &$skipped, &$failed): void {
            foreach ($chunk as $row) {
                try {
                    $outcome = $mapper->apply($row, $strategy);

                    match ($outcome) {
                        MapOutcome::Added => $added++,
                        MapOutcome::Updated => $updated++,
                        MapOutcome::Skipped => $skipped++,
                    };
                } catch (\Throwable $e) {
                    $failed++;

                    Log::warning('Migration row failed', [
                        'entity' => $mapper->key(),
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });

        return [$added, $updated, $skipped, $failed];
    }

    /**
     * Bulk path for the high-volume mappers: transform the whole chunk in memory (no
     * queries), one query to find which rows already exist, then one upsert() for the
     * whole batch — instead of up to 3 queries per row. Falls back to a slower per-row
     * write only if the bulk upsert itself throws, so a single bad row's constraint
     * violation doesn't silently fail the entire chunk without attribution.
     *
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function applyBulkChunk(BulkEntityMapper $mapper, array $chunk, DuplicateStrategy $strategy): array
    {
        $uniqueBy = $mapper->uniqueBy();
        $rows = [];
        $structuralSkipped = 0;

        foreach ($chunk as $legacyRow) {
            $attributes = $mapper->transform($legacyRow);

            if ($attributes === null) {
                $structuralSkipped++;

                continue;
            }

            // Last-wins on a duplicate key within the same chunk (defensive; shouldn't
            // normally happen since legacy_uid is a real unique business key).
            $rows[$attributes[$uniqueBy]] = $attributes;
        }

        if ($rows === []) {
            return [0, 0, $structuralSkipped, 0];
        }

        $model = $mapper->targetModel();
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        $keys = array_keys($rows);

        $existingKeys = array_flip(
            ($usesSoftDeletes ? $model::withTrashed() : $model::query())
                ->whereIn($uniqueBy, $keys)
                ->pluck($uniqueBy)
                ->all()
        );

        if ($strategy === DuplicateStrategy::SkipExisting) {
            $toWrite = array_diff_key($rows, $existingKeys);
            $added = count($toWrite);
            $updated = 0;
            $skipped = $structuralSkipped + (count($rows) - count($toWrite));
        } else {
            $toWrite = $rows;
            $added = count(array_diff_key($rows, $existingKeys));
            $updated = count($rows) - $added;
            $skipped = $structuralSkipped;
        }

        if ($toWrite === []) {
            return [$added, $updated, $skipped, 0];
        }

        try {
            DB::transaction(function () use ($model, $toWrite, $uniqueBy, $mapper): void {
                // MySQL caps a prepared statement at 65535 placeholders, so a single upsert()
                // call can't take all of CHUNK_SIZE's rows at once — split into smaller batches.
                foreach (array_chunk(array_values($toWrite), self::UPSERT_BATCH_SIZE) as $batch) {
                    $model::upsert($batch, [$uniqueBy], $mapper->updatableColumns());
                }
            });

            return [$added, $updated, $skipped, 0];
        } catch (\Throwable $e) {
            Log::warning('Bulk upsert failed for chunk, falling back to per-row writes to isolate the failure', [
                'entity' => $mapper->key(),
                'message' => $e->getMessage(),
            ]);

            return $this->applyBulkChunkFallback($model, $toWrite, $uniqueBy, $usesSoftDeletes, $mapper, $skipped);
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<int|string, array<string, mixed>>  $toWrite
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function applyBulkChunkFallback(string $model, array $toWrite, string $uniqueBy, bool $usesSoftDeletes, BulkEntityMapper $mapper, int $skipped): array
    {
        $added = 0;
        $updated = 0;
        $failed = 0;

        foreach ($toWrite as $key => $attributes) {
            try {
                DB::transaction(function () use ($model, $attributes, $uniqueBy, $key, $usesSoftDeletes, &$added, &$updated): void {
                    $existing = ($usesSoftDeletes ? $model::withTrashed() : $model::query())
                        ->where($uniqueBy, $key)
                        ->first();

                    if ($existing) {
                        $existing->update($attributes);
                        $updated++;
                    } else {
                        $model::create($attributes);
                        $added++;
                    }
                });
            } catch (\Throwable $e) {
                $failed++;

                Log::warning('Migration row failed (bulk-fallback path)', [
                    'entity' => $mapper->key(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [$added, $updated, $skipped, $failed];
    }

    private function ensureFallbackAdminExists(): void
    {
        if (User::where('role', UserRole::Admin)->exists()) {
            return;
        }

        $email = 'migration-admin-'.Str::random(6).'@localhost';
        $password = Str::password(16);

        User::create([
            'name' => 'Migration Admin',
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
        ]);

        $this->run->update([
            'options' => array_merge($this->run->options ?? [], [
                'fallback_admin' => [
                    'email' => $email,
                    'password' => $password,
                ],
            ]),
        ]);
    }

    /**
     * @param  array<int, string>  $selectedGroups
     */
    private function clearData(array $selectedGroups, string $clearMode): void
    {
        // lookup_units/settings are additive-only (no legacy_uid, no stable duplicate signal to
        // safely delete against), so neither clear mode touches them.
        if (in_array('purchase_credit_notes', $selectedGroups, true)) {
            $this->clearTable(SupplierDebitNoteItem::class, $clearMode, softDeletes: false);
            $this->clearTable(SupplierDebitNote::class, $clearMode, softDeletes: true);
        }

        if (in_array('purchase_invoices', $selectedGroups, true)) {
            $this->clearTable(SupplierInvoiceItem::class, $clearMode, softDeletes: false);
            $this->clearTable(SupplierInvoice::class, $clearMode, softDeletes: false);
        }

        if (in_array('documents', $selectedGroups, true)) {
            $this->clearTable(DocumentItem::class, $clearMode, softDeletes: false);
            $this->clearTable(Document::class, $clearMode, softDeletes: true);
        }

        if (in_array('payments', $selectedGroups, true)) {
            // Must precede clearTable(Payment::class, ...) below: clearTable's own
            // forceDelete would otherwise leave payment_allocations FK-orphaned once
            // their payment row is gone (no cascade defined on that column).
            $paymentIdsQuery = Payment::withTrashed();
            if ($clearMode === 'migrated_only') {
                $paymentIdsQuery->whereNotNull('legacy_uid');
            }
            PaymentAllocation::withTrashed()->whereIn('payment_id', $paymentIdsQuery->pluck('id'))->forceDelete();

            $this->clearTable(Payment::class, $clearMode, softDeletes: true);
        }

        if (in_array('suppliers', $selectedGroups, true)) {
            $this->clearTable(Supplier::class, $clearMode, softDeletes: true);
        }

        if (in_array('customers', $selectedGroups, true)) {
            $this->clearTable(Customer::class, $clearMode, softDeletes: true);
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function clearTable(string $modelClass, string $clearMode, bool $softDeletes): void
    {
        DB::transaction(function () use ($modelClass, $clearMode, $softDeletes): void {
            $query = $softDeletes ? $modelClass::withTrashed() : $modelClass::query();

            if ($clearMode === 'migrated_only') {
                $query->whereNotNull('legacy_uid');
            }

            // A single indexed WHERE legacy_uid IS NOT NULL (or unconditional) delete is fine on
            // MySQL for these bounded tables; chunking isn't needed given legacy_uid is indexed.
            if ($softDeletes) {
                $query->forceDelete();
            } else {
                $query->delete();
            }
        });
    }
}
