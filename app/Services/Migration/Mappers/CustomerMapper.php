<?php

namespace App\Services\Migration\Mappers;

use App\Models\Customer;
use App\Models\LookupCreditLimit;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use Illuminate\Support\Facades\DB;

class CustomerMapper implements BulkEntityMapper
{
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
                ->orderBy('Uid')
                ->lazy($chunkSize) as $row
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
        ];
    }

    public function transform(array $legacyRow): ?array
    {
        $vatRegistered = ! empty($legacyRow['vatcode']) || ! empty($legacyRow['vatdiff']);

        $creditLimitId = null;
        if (! empty($legacyRow['crlim']) && (float) $legacyRow['crlim'] > 0) {
            $creditLimitId = LookupCreditLimit::firstOrCreate(['amount' => $legacyRow['crlim']])->id;
        }

        // usrref is the legacy system's own customer reference code (confirmed via
        // CustSuppController.cs, used to look customers up by reference) — preserve it
        // instead of letting our own auto-generation manufacture a new CUST-00001-style
        // number. Fall back to a deterministic legacy-uid-based value on the rare blank
        // row, since bulk upsert() bypasses the model's auto-generation hook entirely.
        $usrref = trim((string) ($legacyRow['usrref'] ?? ''));
        $reference = $usrref !== '' ? $usrref : 'LEGACY-'.$legacyRow['uid'];

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
            // No clean numeric-to-name mapping exists from CustSupps.Term alone; left null as an open item.
            'credit_term_id' => null,
            'credit_limit_id' => $creditLimitId,
            'reference' => $reference,
        ];
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
