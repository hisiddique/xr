<?php

namespace App\Services\Migration\Mappers;

use App\Models\LookupCreditLimit;
use App\Models\Supplier;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use Illuminate\Support\Facades\DB;

class SupplierMapper implements BulkEntityMapper
{
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
                ->orderBy('Uid')
                ->lazy($chunkSize) as $row
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
        ];
    }

    public function transform(array $legacyRow): ?array
    {
        $vatRegistered = ! empty($legacyRow['vatcode']) || ! empty($legacyRow['vatdiff']);

        $creditLimitId = null;
        if (! empty($legacyRow['crlim']) && (float) $legacyRow['crlim'] > 0) {
            $creditLimitId = LookupCreditLimit::firstOrCreate(['amount' => $legacyRow['crlim']])->id;
        }

        // usrref is the legacy system's own supplier reference code — preserve it instead
        // of letting our own auto-generation manufacture a new SUP-0001-style number. Fall
        // back to a deterministic legacy-uid-based value on the rare blank row, since bulk
        // upsert() bypasses the model's auto-generation hook entirely.
        $usrref = trim((string) ($legacyRow['usrref'] ?? ''));
        $reference = $usrref !== '' ? $usrref : 'LEGACY-'.$legacyRow['uid'];

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
            // No clean numeric-to-name mapping exists from CustSupps.Term alone; left null as an open item.
            'credit_term_id' => null,
            'credit_limit_id' => $creditLimitId,
            'reference' => $reference,
        ];
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
