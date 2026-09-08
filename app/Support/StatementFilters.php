<?php

namespace App\Support;

use Carbon\CarbonInterface;

final readonly class StatementFilters
{
    /**
     * @param  array<int, int>  $paymentMethods  lookup_payment_methods ids; empty = all
     */
    public function __construct(
        public StatementPeriod $period,
        public bool $outstandingOnly,
        public ?string $minBalance,
        public bool $includeInvoices,
        public bool $includeCreditNotes,
        public bool $includeWriteOffs,
        public bool $includePayments,
        public array $paymentMethods,
    ) {}

    /**
     * Build from a schedule `rules` JSON array (customer arm keys: preset, date_from, date_to,
     * outstanding_only, min_balance, include_invoices, include_credit_notes, include_write_offs,
     * include_payments, payment_methods).
     *
     * @param  array<string, mixed>  $rules
     */
    public static function fromRules(array $rules, ?CarbonInterface $asOf = null): self
    {
        $period = StatementPeriod::fromPreset(
            (string) ($rules['preset'] ?? 'this_month'),
            $rules['date_from'] ?? null,
            $rules['date_to'] ?? null,
            $asOf,
        );

        return new self(
            period: $period,
            outstandingOnly: (bool) ($rules['outstanding_only'] ?? true),
            minBalance: isset($rules['min_balance']) && $rules['min_balance'] !== '' ? (string) $rules['min_balance'] : null,
            includeInvoices: (bool) ($rules['include_invoices'] ?? true),
            includeCreditNotes: (bool) ($rules['include_credit_notes'] ?? false),
            includeWriteOffs: (bool) ($rules['include_write_offs'] ?? false),
            includePayments: (bool) ($rules['include_payments'] ?? false),
            paymentMethods: array_values(array_map('intval', $rules['payment_methods'] ?? [])),
        );
    }

    /**
     * The flat array shape consumed by CustomerStatementService / SupplierStatementService.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dateFrom' => $this->period->from ?? '',
            'dateTo' => $this->period->to ?? '',
            'outstandingOnly' => $this->outstandingOnly,
            'minBalance' => $this->minBalance ?? '',
            'includeInvoices' => $this->includeInvoices,
            'includeCreditNotes' => $this->includeCreditNotes,
            'includeWriteOffs' => $this->includeWriteOffs,
            'includePayments' => $this->includePayments,
            'paymentMethods' => $this->paymentMethods,
        ];
    }
}
