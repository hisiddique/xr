<?php

namespace App\Services;

use App\Mail\CustomerOutstandingReportMail;
use App\Models\Customer;
use App\Models\Document;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use League\Csv\Writer as CsvWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerOutstandingReportService
{
    /** @var array<int, string> */
    protected const EXPORT_HEADINGS = ['Customer', 'Reference', 'Date', 'Invoice', 'Total', 'Outstanding'];

    protected const OUTSTANDING_EXPR = '(documents.total_value
        - COALESCE((select sum(pa.allocated_amount) from payment_allocations pa where pa.document_id = documents.id and pa.deleted_at is null), 0)
        - COALESCE((select sum(ca.amount) from credit_allocations ca where ca.invoice_id = documents.id and ca.deleted_at is null), 0))';

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyOutstandingFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->withSum('paymentAllocations as allocated_total', 'allocated_amount')
            ->withSum('creditAllocationsReceived as credited_total', 'amount')
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '<=', $date))
            ->when(is_numeric($filters['amountMin'] ?? null), fn ($q) => $q->where('total_value', '>=', (float) $filters['amountMin']))
            ->when(is_numeric($filters['amountMax'] ?? null), fn ($q) => $q->where('total_value', '<=', (float) $filters['amountMax']))
            ->whereRaw(self::OUTSTANDING_EXPR.' > 0.001')
            ->when(is_numeric($filters['osMin'] ?? null), fn ($q) => $q->whereRaw(self::OUTSTANDING_EXPR.' >= CAST(? AS DECIMAL(15,2))', [(float) $filters['osMin']]))
            ->when(is_numeric($filters['osMax'] ?? null), fn ($q) => $q->whereRaw(self::OUTSTANDING_EXPR.' <= CAST(? AS DECIMAL(15,2))', [(float) $filters['osMax']]));
    }

    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '%_');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function customersQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Customer::query()
            ->whereHas('invoices', fn ($q) => $this->applyOutstandingFilters($q, $filters))
            ->when($filters['customerId'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->when($search !== '', function ($q) use ($search, $filters) {
                $like = '%'.$this->escapeLike($search).'%';

                $q->where(function ($q) use ($like, $filters) {
                    $q->where('company_name', 'like', $like)
                        ->orWhere('reference', 'like', $like)
                        ->orWhereHas('invoices', fn ($q) => $this->applyOutstandingFilters($q, $filters)
                            ->where('doc_number', 'like', $like));
                });
            })
            ->with(['invoices' => fn ($q) => $this->applyOutstandingFilters($q, $filters)->orderBy('doc_date')])
            ->orderBy('company_name');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Customer>
     */
    public function customersForExport(array $filters): Collection
    {
        return $this->customersQuery($filters)->get();
    }

    public function outstandingAmount(Document $invoice): float
    {
        return (float) $invoice->total_value - (float) ($invoice->allocated_total ?? 0) - (float) ($invoice->credited_total ?? 0);
    }

    public function customerOutstandingTotal(Customer $customer): float
    {
        return $customer->invoices->sum(fn (Document $invoice) => $this->outstandingAmount($invoice));
    }

    /**
     * Build export rows grouped per customer: each customer's invoice rows
     * followed by a subtotal row, mirroring the on-screen table layout.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<int, array{0: array<int, string>, 1: bool}>
     */
    protected function exportRows(Collection $customers): array
    {
        $rows = [];

        foreach ($customers as $customer) {
            foreach ($customer->invoices as $invoice) {
                $rows[] = [[
                    $customer->company_name.' ('.$customer->reference.')',
                    $invoice->doc_date?->format('d M Y') ?? '',
                    $invoice->doc_number,
                    number_format((float) $invoice->total_value, 2, '.', ''),
                    number_format($this->outstandingAmount($invoice), 2, '.', ''),
                ], false];
            }

            $rows[] = [[
                '', '', '',
                number_format((float) $customer->invoices->sum('total_value'), 2, '.', ''),
                number_format($this->customerOutstandingTotal($customer), 2, '.', ''),
            ], true];
        }

        return $rows;
    }

    public function streamCsv(Collection $customers): StreamedResponse
    {
        return response()->streamDownload(function () use ($customers) {
            $writer = CsvWriter::createFromStream(fopen('php://output', 'w'));
            $writer->insertOne(self::EXPORT_HEADINGS);

            foreach ($this->exportRows($customers) as [$row]) {
                $writer->insertOne($row);
            }
        }, 'customer-outstanding-payments.csv', ['Content-Type' => 'text/csv']);
    }

    protected function csvBinary(Collection $customers): string
    {
        $writer = CsvWriter::createFromString();
        $writer->insertOne(self::EXPORT_HEADINGS);

        foreach ($this->exportRows($customers) as [$row]) {
            $writer->insertOne($row);
        }

        return $writer->toString();
    }

    protected function writeXlsx(XlsxWriter $writer, Collection $customers): void
    {
        $boldStyle = (new Style)->withFontBold(true);

        $writer->addRow(Row::fromValuesWithStyle(self::EXPORT_HEADINGS, $boldStyle));

        foreach ($this->exportRows($customers) as [$row, $isSubtotal]) {
            $writer->addRow($isSubtotal ? Row::fromValuesWithStyle($row, $boldStyle) : Row::fromValues($row));
        }
    }

    public function streamXlsx(Collection $customers): StreamedResponse
    {
        return response()->streamDownload(function () use ($customers) {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');

            try {
                $this->writeXlsx($writer, $customers);
            } finally {
                $writer->close();
            }
        }, 'customer-outstanding-payments.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected function xlsxBinary(Collection $customers): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx');

        $writer = new XlsxWriter;
        $writer->openToFile($tmpPath);

        try {
            $this->writeXlsx($writer, $customers);
        } finally {
            $writer->close();
        }

        $data = file_get_contents($tmpPath);
        unlink($tmpPath);

        return $data;
    }

    protected function pdfBinary(Collection $customers): string
    {
        return Pdf::loadView('pdfs.customer-outstanding', ['customers' => $customers, 'reportService' => $this])
            ->setOption('isPhpEnabled', true)
            ->output();
    }

    public function streamPdf(Collection $customers): Response
    {
        return Pdf::loadView('pdfs.customer-outstanding', ['customers' => $customers, 'reportService' => $this])
            ->setOption('isPhpEnabled', true)
            ->download('customer-outstanding-payments.pdf');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $emails
     * @param  array<int, string>  $formats
     */
    public function sendReport(array $filters, array $emails, array $formats, ?string $notes = null): void
    {
        $customers = $this->customersForExport($filters);

        $totalOutstanding = $customers->sum(fn (Customer $customer) => $this->customerOutstandingTotal($customer));

        $attachments = [];

        if (in_array('pdf', $formats, true)) {
            $attachments[] = [
                'data' => $this->pdfBinary($customers),
                'filename' => 'customer-outstanding-payments.pdf',
                'mime' => 'application/pdf',
            ];
        }

        if (in_array('csv', $formats, true)) {
            $attachments[] = [
                'data' => $this->csvBinary($customers),
                'filename' => 'customer-outstanding-payments.csv',
                'mime' => 'text/csv',
            ];
        }

        if (in_array('xlsx', $formats, true)) {
            $attachments[] = [
                'data' => $this->xlsxBinary($customers),
                'filename' => 'customer-outstanding-payments.xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        Mail::to($emails)->send(new CustomerOutstandingReportMail(
            $customers->count(),
            $totalOutstanding,
            $notes,
            $attachments,
        ));
    }
}
