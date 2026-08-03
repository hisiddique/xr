<?php

namespace App\Services\Migration\Mappers;

use App\Models\LookupCreditLimit;
use App\Models\LookupCreditTerm;
use App\Models\Supplier;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Support\LegacyDate;
use Illuminate\Support\Facades\DB;

class SupplierMapper implements BulkEntityMapper
{
    private ?int $createdBy = null;

    /** @var array<int, string>|null Maps AppCodes.Valueint => Description for Codetype='Cust-CreditTerms' */
    private ?array $creditTermLabelByValue = null;

    public function setCreatedBy(int $userId): static
    {
        $this->createdBy = $userId;

        return $this;
    }

    public function key(): string
    {
        return 'suppliers';
    }

    public function label(): string
    {
        return 'Suppliers';
    }

    public function count(): int
    {
        return DB::connection('legacy')->table('CustSupps')->where('Rtype', 'B')->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach (
            DB::connection('legacy')->table('CustSupps')
                ->where('Rtype', 'B')
                ->lazyById($chunkSize, 'Uid', 'uid') as $row
        ) {
            yield (array) $row;
        }
    }

    public function targetModel(): string
    {
        return Supplier::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return [
            'address_line_1',
            'address_line_2',
            'town_city',
            'post_code',
            'email',
            'supplier_vat_number',
            'vat_applied',
            'vat_registered',
            'trade_discount',
            'credit_term_id',
            'credit_limit_id',
            'reference',
            'company_name',
            'created_by',
            'created_at',
            'updated_at',
        ];
    }

    public function transform(array $legacyRow): ?array
    {
        $vatRegistered = ! empty($legacyRow['vatcode']) || ! empty($legacyRow['vatdiff']);

        $creditLimitId = null;
        if (! empty($legacyRow['crlim']) && (float) $legacyRow['crlim'] > 0) {
            $creditLimitId = LookupCreditLimit::firstOrCreate(['amount' => $legacyRow['crlim']])->id;
        }

        $usrref = trim((string) ($legacyRow['usrref'] ?? ''));
        $reference = $usrref !== '' ? $usrref : (string) $legacyRow['uid'];

        $createdAt = LegacyDate::parse($legacyRow['createddate'] ?? null) ?? now();
        $updatedAt = LegacyDate::parse($legacyRow['modifieddate'] ?? null) ?? $createdAt;

        return [
            'legacy_uid' => $legacyRow['uid'],
            'company_name' => $legacyRow['name'] ?? null,
            'address_line_1' => $legacyRow['add1'] ?? null,
            'address_line_2' => $legacyRow['add2'] ?? null,
            'town_city' => $legacyRow['town'] ?? null,
            'post_code' => $legacyRow['pcode'] ?? null,
            'email' => $legacyRow['email'] ?? null,
            'supplier_vat_number' => $legacyRow['regn'] ?? null,
            'trade_discount' => $legacyRow['disc'] ?? 0,
            // No separate legacy signal distinguishes vat_applied from vat_registered; both derive
            // from the same Vatcode/Vatdiff check.
            'vat_applied' => $vatRegistered,
            'vat_registered' => $vatRegistered,
            'credit_term_id' => $this->resolveCreditTermId($legacyRow['term'] ?? null),
            'credit_limit_id' => $creditLimitId,
            'reference' => $reference,
            'created_by' => $this->createdBy,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * CustSupps.Term is resolved via AppCodes (Codetype='Cust-CreditTerms', Valueint -> Description),
     * confirmed against the legacy UI's credit-terms dropdown — the same codetype is reused for both
     * customers and suppliers (Views/CustSupp/_SupplierPagesPartial.cshtml).
     */
    private function resolveCreditTermId(mixed $term): ?int
    {
        if ($term === null || $term === '') {
            return null;
        }

        if ($this->creditTermLabelByValue === null) {
            $this->creditTermLabelByValue = DB::connection('legacy')->table('AppCodes')
                ->where('codetype', 'Cust-CreditTerms')
                ->pluck('description', 'valueint')
                ->all();
        }

        $label = $this->creditTermLabelByValue[(int) $term] ?? null;

        if ($label === null) {
            return null;
        }

        return LookupCreditTerm::firstOrCreate(['name' => trim($label)])->id;
    }

    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        $existing = Supplier::withTrashed()->where('legacy_uid', $legacyRow['uid'])->first();

        if ($existing && $strategy === DuplicateStrategy::SkipExisting) {
            return MapOutcome::Skipped;
        }

        $attributes = $this->transform($legacyRow);

        if ($existing) {
            $existing->update($attributes);

            return MapOutcome::Updated;
        }

        Supplier::create($attributes);

        return MapOutcome::Added;
    }
}
