<?php

namespace App\Services\Migration\Mappers;

use App\Models\Customer;
use App\Models\LookupCreditLimit;
use App\Models\LookupCreditTerm;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use Illuminate\Support\Facades\DB;

class CustomerMapper implements BulkEntityMapper
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
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers';
    }

    public function count(): int
    {
        return DB::connection('legacy')->table('CustSupps')->where('Rtype', 'A')->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach (
            DB::connection('legacy')->table('CustSupps')
                ->where('Rtype', 'A')
                ->lazyById($chunkSize, 'Uid', 'uid') as $row
        ) {
            yield (array) $row;
        }
    }

    public function targetModel(): string
    {
        return Customer::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return [
            'company_name',
            'address_1',
            'address_2',
            'town',
            'post_code',
            'email_1',
            'trade_discount',
            'vat_registered',
            'credit_term_id',
            'credit_limit_id',
            'reference',
            'created_by',
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

        return [
            'legacy_uid' => $legacyRow['uid'],
            'company_name' => $legacyRow['name'] ?? null,
            'address_1' => $legacyRow['add1'] ?? null,
            'address_2' => $legacyRow['add2'] ?? null,
            'town' => $legacyRow['town'] ?? null,
            'post_code' => $legacyRow['pcode'] ?? null,
            'email_1' => $legacyRow['email'] ?? null,
            'trade_discount' => $legacyRow['disc'] ?? 0,
            'vat_registered' => $vatRegistered,
            'credit_term_id' => $this->resolveCreditTermId($legacyRow['term'] ?? null),
            'credit_limit_id' => $creditLimitId,
            'reference' => $reference,
            'created_by' => $this->createdBy,
        ];
    }

    /**
     * CustSupps.Term is resolved via AppCodes (Codetype='Cust-CreditTerms', Valueint -> Description),
     * confirmed against the legacy UI's credit-terms dropdown (Views/CustSupp/_CustomerPagesPartial.cshtml).
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
        $existing = Customer::withTrashed()->where('legacy_uid', $legacyRow['uid'])->first();

        if ($existing && $strategy === DuplicateStrategy::SkipExisting) {
            return MapOutcome::Skipped;
        }

        $attributes = $this->transform($legacyRow);

        if ($existing) {
            $existing->update($attributes);

            return MapOutcome::Updated;
        }

        Customer::create($attributes);

        return MapOutcome::Added;
    }
}
