<?php

namespace App\Services;

use App\Mail\CustomerStatementMail;
use App\Models\Customer;
use App\Models\Document;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class CustomerStatementService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{doc_date: ?string, doc_number: string, order_no: ?string, total_value: float, outstanding: float, credited: float}>
     */
    public function buildInvoiceRows(Customer $customer, array $filters): array
    {
        $rows = $customer->invoices()
            ->withSum('paymentAllocations as allocated_total', 'allocated_amount')
            ->withSum('creditAllocationsReceived as credited_total', 'amount')
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '<=', $date))
            ->when(! empty($filters['outstandingOnly']), fn ($q) => $q->where('is_settled', false))
            ->orderBy('doc_date')
            ->get()
            ->map(function (Document $invoice) {
                $credited = (float) ($invoice->credited_total ?? 0);
                $allocated = (float) ($invoice->allocated_total ?? 0);

                return [
                    'doc_date' => $invoice->doc_date?->format('d M Y'),
                    'doc_number' => $invoice->doc_number,
                    'order_no' => $invoice->order_no,
                    'total_value' => (float) $invoice->total_value,
                    'outstanding' => (float) $invoice->total_value - $allocated - $credited,
                    'credited' => $credited,
                ];
            });

        $minBalance = $filters['minBalance'] ?? null;

        if (is_numeric($minBalance)) {
            $rows = $rows->filter(fn (array $row) => $row['outstanding'] >= (float) $minBalance)->values();
        }

        return $rows->all();
    }

    /**
     * Groups outstanding amounts for the last 4 full calendar months into aging
     * buckets, with anything older falling into an 'Older' bucket. Invoices
     * dated in the current calendar month or later are excluded.
     *
     * @param  array<int, array{doc_date: ?string, outstanding: float}>  $invoiceRows
     * @return array{labels: array<string, float>, total: float}
     */
    public function agingBuckets(array $invoiceRows): array
    {
        $now = now();
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
    public function pdfBinary(Customer $customer, array $filters): string
    {
        $rows = $this->buildInvoiceRows($customer, $filters);

        return Pdf::loadView('pdfs.customer-statement', [
            'customer' => $customer,
            'invoices' => $rows,
            'aging' => $this->agingBuckets($rows),
        ])
            ->setOption('isPhpEnabled', true)
            ->output();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function streamPdf(Customer $customer, array $filters, bool $inline = false): Response
    {
        $rows = $this->buildInvoiceRows($customer, $filters);

        $pdf = Pdf::loadView('pdfs.customer-statement', [
            'customer' => $customer,
            'invoices' => $rows,
            'aging' => $this->agingBuckets($rows),
        ])
            ->setOption('isPhpEnabled', true);

        $filename = 'customer-statement-'.$customer->reference.'.pdf';

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $emails
     */
    public function sendStatementEmail(Customer $customer, array $filters, array $emails, ?string $notes = null): void
    {
        $rows = $this->buildInvoiceRows($customer, $filters);
        $aging = $this->agingBuckets($rows);

        $pdfBinary = Pdf::loadView('pdfs.customer-statement', [
            'customer' => $customer,
            'invoices' => $rows,
            'aging' => $aging,
        ])
            ->setOption('isPhpEnabled', true)
            ->output();

        Mail::to($emails)->send(new CustomerStatementMail(
            $customer,
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
