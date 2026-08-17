<?php

namespace App\Services\Migration\Mappers;

use App\Models\Customer;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\PaymentSourceType;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\ReportsExcludedRows;
use App\Services\Migration\Support\LegacyDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PaymentMapper implements BulkEntityMapper, ReportsExcludedRows
{
    private ?int $createdBy = null;

    /** @var array<int, int>|null Maps customers.legacy_uid => customers.id */
    private ?array $customerIdByLegacyUid = null;

    /** @var array<string, int>|null Maps lookup_payment_methods.name => id */
    private ?array $paymentMethodIdByName = null;

    /** @var array<int, string>|null Maps AccountEntries.uid => generated payments.reference */
    private ?array $referenceByLegacyUid = null;

    public function setCreatedBy(int $userId): static
    {
        $this->createdBy = $userId;

        return $this;
    }

    public function key(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return 'Customer Payments';
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach ($this->baseQuery()->lazyById($chunkSize, 'AccountEntries.uid', 'uid') as $row) {
            yield (array) $row;
        }
    }

    public function excludedCount(): int
    {
        return $this->baseQuery()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('CustSupps')
                    ->where('CustSupps.Rtype', 'A')
                    ->whereColumn('CustSupps.Uid', 'AccountEntries.custid');
            })
            ->count();
    }

    public function excludedLabel(): string
    {
        return 'reference a customer that does not exist anywhere in the legacy data';
    }

    private function baseQuery(): Builder
    {
        return DB::connection('legacy')->table('AccountEntries')
            ->join('AccountPostTypes', function ($join) {
                $join->on('AccountEntries.posttype', '=', 'AccountPostTypes.uid')
                    ->where('AccountPostTypes.rtype', '=', 'A')
                    ->where('AccountPostTypes.inout', '=', 'IN');
            })
            ->where('AccountEntries.rtype', '=', 'a')
            ->select('AccountEntries.*');
    }

    /**
     * Legacy receipts have no natural reference number, so one is generated once
     * (ordered by txndate, then uid) and never rewritten on a re-run.
     *
     * @return array<int, string>
     */
    private function legacyReferenceByUid(): array
    {
        if ($this->referenceByLegacyUid !== null) {
            return $this->referenceByLegacyUid;
        }

        $rows = $this->baseQuery()
            ->select('AccountEntries.uid')
            ->selectRaw('ROW_NUMBER() OVER (ORDER BY AccountEntries.txndate, AccountEntries.uid) as ordinal')
            ->get();

        $this->referenceByLegacyUid = [];

        foreach ($rows as $row) {
            $this->referenceByLegacyUid[(int) $row->uid] = 'PAY-L-'.str_pad((string) $row->ordinal, 4, '0', STR_PAD_LEFT);
        }

        return $this->referenceByLegacyUid;
    }

    /**
     * Invno carries a free-text payment-method hint (BACS/PDQ/CHQ prefixes observed in
     * the legacy data); anything else defaults to Cash.
     */
    private function resolveMethodId(string $invno): int
    {
        $normalized = strtoupper(trim($invno));

        $name = match (true) {
            str_starts_with($normalized, 'BACS') => 'Bank Transfer',
            str_starts_with($normalized, 'PDQ') => 'Card',
            str_starts_with($normalized, 'CHQ') => 'Cheque',
            default => 'Cash',
        };

        if ($this->paymentMethodIdByName === null) {
            $this->paymentMethodIdByName = LookupPaymentMethod::pluck('id', 'name')->all();
        }

        $id = $this->paymentMethodIdByName[$name] ?? null;

        if ($id === null) {
            throw new \RuntimeException("Required payment method lookup '{$name}' is missing — seed lookup_payment_methods before migrating payments.");
        }

        return $id;
    }

    public function targetModel(): string
    {
        return Payment::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return [
            'customer_id', 'payment_method_id', 'source_type', 'payment_reference',
            'amount', 'is_exhausted', 'payment_date', 'notes', 'receipt_path',
            'created_by', 'created_at', 'updated_at',
        ];
    }

    public function transform(array $legacyRow): ?array
    {
        if ($this->customerIdByLegacyUid === null) {
            $this->customerIdByLegacyUid = Customer::withTrashed()
                ->whereNotNull('legacy_uid')
                ->pluck('id', 'legacy_uid')
                ->all();
        }

        $customerId = $this->customerIdByLegacyUid[$legacyRow['custid']] ?? null;

        if ($customerId === null) {
            return null;
        }

        $amount = abs((float) ($legacyRow['value'] ?? 0));

        if ($amount <= 0) {
            return null;
        }

        $paymentDate = LegacyDate::parse($legacyRow['txndate'] ?? null);

        if ($paymentDate === null) {
            return null;
        }

        $invno = trim((string) ($legacyRow['invno'] ?? ''));

        $createdAt = LegacyDate::parse($legacyRow['createddate'] ?? null) ?? now();
        $updatedAt = LegacyDate::parse($legacyRow['modifieddate'] ?? null) ?? $createdAt;

        return [
            'legacy_uid' => $legacyRow['uid'],
            'reference' => $this->legacyReferenceByUid()[$legacyRow['uid']] ?? null,
            'customer_id' => $customerId,
            'payment_method_id' => $this->resolveMethodId($invno),
            'source_type' => PaymentSourceType::Cash->value,
            'payment_reference' => $invno !== '' ? $invno : null,
            'amount' => $amount,
            'is_exhausted' => false,
            'payment_date' => $paymentDate,
            'notes' => null,
            'receipt_path' => null,
            'created_by' => $this->createdBy,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Required by EntityMapper (which BulkEntityMapper extends) but never called by
     * MigrationRunner for bulk mappers — it always uses transform() + upsert() instead.
     * Kept as a correct, working single-row fallback for interface compliance.
     */
    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        $existing = Payment::withTrashed()->where('legacy_uid', $legacyRow['uid'])->first();

        if ($existing && $strategy === DuplicateStrategy::SkipExisting) {
            return MapOutcome::Skipped;
        }

        $attributes = $this->transform($legacyRow);

        if ($attributes === null) {
            return MapOutcome::Skipped;
        }

        if ($existing) {
            $existing->update($attributes);

            return MapOutcome::Updated;
        }

        Payment::create($attributes);

        return MapOutcome::Added;
    }
}
