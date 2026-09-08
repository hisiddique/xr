<?php

namespace App\Services;

use App\Mail\SupplierStatementMail;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class SupplierStatementService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{doc_date: ?string, doc_number: string, order_no: ?string, row_type: string, total_value: float, outstanding: float, credited: float}>
     */
    public function buildInvoiceRows(Supplier $supplier, array $filters): array
    {
        $rows = $supplier->supplierInvoices()
            ->with(['items', 'payoutAllocations', 'debitNotes'])
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('invoice_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('invoice_date', '<=', $date))
            ->get()
            ->map(fn (SupplierInvoice $invoice) => [
                'doc_date' => $invoice->invoice_date?->format('d M Y'),
                'doc_number' => $invoice->supplier_invoice_no,
                'order_no' => $invoice->supplier_ref_invoice_no,
                'row_type' => 'invoice',
                'total_value' => (float) $invoice->payableTotal,
                'outstanding' => (float) $invoice->outstandingAmount,
                'credited' => (float) $invoice->payableTotal - (float) $invoice->outstandingAmount,
            ]);

        if (! empty($filters['outstandingOnly'])) {
            $rows = $rows->filter(fn (array $row) => $row['outstanding'] > 0.005)->values();
        }

        $minBalance = $filters['minBalance'] ?? null;

        if (is_numeric($minBalance)) {
            $rows = $rows->filter(fn (array $row) => $row['outstanding'] >= (float) $minBalance)->values();
        }

        return $rows->values()->all();
    }

    /**
     * Debit notes listed for reference only: the applied portion is already netted into
     * the related invoice's `outstanding` via `debitNotes` in buildInvoiceRows(), so these
     * rows carry `outstanding => 0` to avoid subtracting the same credit twice from the
     * aging total.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{doc_date: ?string, doc_number: string, order_no: ?string, row_type: string, total_value: float, outstanding: float, credited: float}>
     */
    public function buildDebitNoteRows(Supplier $supplier, array $filters): array
    {
        return $supplier->debitNotes()
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '<=', $date))
            ->orderBy('doc_date')
            ->get()
            ->map(fn (SupplierDebitNote $debitNote) => [
                'doc_date' => $debitNote->doc_date?->format('d M Y'),
                'doc_number' => $debitNote->reference,
                'order_no' => null,
                'row_type' => 'debit_note',
                'total_value' => 0.0,
                'outstanding' => 0.0,
                'credited' => (float) $debitNote->total,
            ])
            ->all();
    }

    /**
     * Payouts listed for reference only: they're already netted into the related invoice's
     * `outstanding` via `payoutAllocations` in buildInvoiceRows(), so these rows carry
     * `outstanding => 0` to avoid subtracting the same payout twice from the aging total.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{doc_date: ?string, doc_number: string, order_no: ?string, row_type: string, total_value: float, outstanding: float, credited: float}>
     */
    public function buildPayoutRows(Supplier $supplier, array $filters): array
    {
        return $supplier->payouts()
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('payout_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('payout_date', '<=', $date))
            ->orderBy('payout_date')
            ->get()
            ->map(fn (SupplierPayout $payout) => [
                'doc_date' => $payout->payout_date?->format('d M Y'),
                'doc_number' => $payout->reference,
                'order_no' => null,
                'row_type' => 'payout',
                'total_value' => 0.0,
                'outstanding' => 0.0,
                'credited' => (float) $payout->amount,
            ])
            ->all();
    }

    /**
     * Merges whichever row types are selected (defaults to invoices only) into one
     * chronologically-sorted list for the statement. Only invoice rows contribute to the
     * aging total — debit note and payout rows are informational (see their builders).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{doc_date: ?string, doc_number: string, order_no: ?string, row_type: string, total_value: float, outstanding: float, credited: float}>
     */
    public function buildStatementRows(Supplier $supplier, array $filters): array
    {
        $rows = collect();

        if ($filters['includeInvoices'] ?? true) {
            $rows = $rows->concat($this->buildInvoiceRows($supplier, $filters));
        }

        if (! empty($filters['includeDebitNotes'])) {
            $rows = $rows->concat($this->buildDebitNoteRows($supplier, $filters));
        }

        if (! empty($filters['includePayouts'])) {
            $rows = $rows->concat($this->buildPayoutRows($supplier, $filters));
        }

        return $rows
            ->sortBy(fn (array $row) => $row['doc_date'] ? Carbon::createFromFormat('d M Y', $row['doc_date']) : Carbon::maxValue())
            ->values()
            ->all();
    }

    /**
     * Groups outstanding amounts for the last 4 full calendar months into aging
     * buckets, with anything older falling into an 'Older' bucket. Invoices
     * dated in the current calendar month or later are excluded.
     *
     * @param  array<int, array{doc_date: ?string, outstanding: float}>  $invoiceRows
     * @return array{labels: array<string, float>, total: float}
     */
    public function agingBuckets(array $invoiceRows, ?CarbonInterface $asOf = null): array
    {
        $now = $asOf ? Carbon::parse($asOf) : now();
        $currentYearMonth = $now->format('Y-m');

        $buckets = [];
        $bucketKeys = [];

        for ($i = 1; $i <= 4; $i++) {
            $month = $now->clone()->subMonthNoOverflow($i);
            $label = $month->format('F');
            $bucketKeys[] = $month->format('Y-m');
            $buckets[$label] = 0.0;
        }

        $buckets['Older'] = 0.0;

        foreach ($invoiceRows as $invoice) {
            if (! $invoice['doc_date']) {
                continue;
            }

            $docDate = Carbon::createFromFormat('d M Y', $invoice['doc_date']);
            $yearMonth = $docDate->format('Y-m');

            if ($yearMonth >= $currentYearMonth) {
                continue;
            }

            $matched = false;

            foreach ($bucketKeys as $index => $key) {
                if ($key === $yearMonth) {
                    $label = array_keys($buckets)[$index];
                    $buckets[$label] += $invoice['outstanding'];
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $buckets['Older'] += $invoice['outstanding'];
            }
        }

        return [
            'labels' => $buckets,
            'total' => array_sum($buckets),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function pdfBinary(Supplier $supplier, array $filters, ?CarbonInterface $asOf = null): string
    {
        $rows = $this->buildStatementRows($supplier, $filters);

        return Pdf::loadView('pdfs.supplier-statement', [
            'supplier' => $supplier,
            'invoices' => $rows,
            'aging' => $this->agingBuckets($rows, $asOf),
        ])
            ->setOption('isPhpEnabled', true)
            ->output();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function streamPdf(Supplier $supplier, array $filters, bool $inline = false, ?CarbonInterface $asOf = null): Response
    {
        $rows = $this->buildStatementRows($supplier, $filters);

        $pdf = Pdf::loadView('pdfs.supplier-statement', [
            'supplier' => $supplier,
            'invoices' => $rows,
            'aging' => $this->agingBuckets($rows, $asOf),
        ])
            ->setOption('isPhpEnabled', true);

        $filename = 'supplier-statement-'.$supplier->reference.'.pdf';

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $emails
     */
    public function sendStatementEmail(Supplier $supplier, array $filters, array $emails, ?string $notes = null, ?CarbonInterface $asOf = null): void
    {
        $rows = $this->buildStatementRows($supplier, $filters);
        $aging = $this->agingBuckets($rows, $asOf);

        $pdfBinary = Pdf::loadView('pdfs.supplier-statement', [
            'supplier' => $supplier,
            'invoices' => $rows,
            'aging' => $aging,
        ])
            ->setOption('isPhpEnabled', true)
            ->output();

        Mail::to($emails)->send(new SupplierStatementMail(
            $supplier,
            $this->periodLabel($filters),
            (float) array_sum(array_column($rows, 'outstanding')),
            $notes,
            $pdfBinary,
        ));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function periodLabel(array $filters): string
    {
        $from = $filters['dateFrom'] ?? '';
        $to = $filters['dateTo'] ?? '';

        if ($from && $to) {
            return Carbon::parse($from)->format('d M Y').' – '.Carbon::parse($to)->format('d M Y');
        }

        return 'All dates';
    }
}
