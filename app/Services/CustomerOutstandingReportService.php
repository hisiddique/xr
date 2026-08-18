<?php

namespace App\Services;

use App\Mail\CustomerOutstandingReportMail;
use App\Models\Customer;
use App\Models\Document;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
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

    protected const EXPORT_CHUNK_SIZE = 200;

    /**
     * dompdf cannot stream — it must hold the fully-rendered document in memory
     * regardless of how the data is built. Above this many invoice rows the
     * render is both slow and memory-risky on shared hosting, so PDF exports
     * are rejected in favour of CSV/XLSX rather than attempting them.
     */
    public const PDF_ROW_CAP = 5000;

    protected const OUTSTANDING_EXPR = '(documents.total_value
        - COALESCE((select sum(pa.allocated_amount) from payment_allocations pa where pa.document_id = documents.id and pa.deleted_at is null), 0)
        - COALESCE((select sum(ca.amount) from credit_allocations ca where ca.invoice_id = documents.id and ca.deleted_at is null), 0))';

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyOutstandingFilters(Builder $query, array $filters): Builder
    {
        $showPaid = (bool) ($filters['showPaid'] ?? false);

        return $query
            ->withSum('paymentAllocations as allocated_total', 'allocated_amount')
            ->withSum('creditAllocationsReceived as credited_total', 'amount')
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '<=', $date))
            ->when(is_numeric($filters['amountMin'] ?? null), fn ($q) => $q->where('total_value', '>=', (float) $filters['amountMin']))
            ->when(is_numeric($filters['amountMax'] ?? null), fn ($q) => $q->where('total_value', '<=', (float) $filters['amountMax']))
            ->where(function ($q) use ($showPaid) {
                $q->where(function ($q2) {
                    $q2->whereRaw(self::OUTSTANDING_EXPR.' > 0.001')->where('is_settled', false);
                });

                if ($showPaid) {
                    $q->orWhere('is_settled', true);
                }
            })
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

    public function outstandingAmount(Document $invoice): float
    {
        return (float) $invoice->total_value - (float) ($invoice->allocated_total ?? 0) - (float) ($invoice->credited_total ?? 0);
    }

    public function customerOutstandingTotal(Customer $customer): float
    {
        return $customer->invoices->sum(fn (Document $invoice) => $this->outstandingAmount($invoice));
    }

    /**
     * Yield export rows one customer at a time using keyset pagination on
     * (company_name, id) instead of chunkById, so ordering is preserved
     * without a final in-memory sort and peak memory stays bounded regardless
     * of total dataset size.
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>
     */
    public function exportChunks(array $filters): \Generator
    {
        $cursor = null;

        while (true) {
            $query = $this->customersQuery($filters)
                ->reorder()
                ->select('customers.id', 'customers.company_name', 'customers.reference')
                ->orderBy('company_name')
                ->orderBy('id');

            if ($cursor !== null) {
                [$lastName, $lastId] = $cursor;
                $query->where(function ($q) use ($lastName, $lastId) {
                    $q->where('company_name', '>', $lastName)
                        ->orWhere(function ($q2) use ($lastName, $lastId) {
                            $q2->where('company_name', $lastName)->where('id', '>', $lastId);
                        });
                });
            }

            $customersChunk = $query->limit(self::EXPORT_CHUNK_SIZE)->get();

            if ($customersChunk->isEmpty()) {
                return;
            }

            $invoicesByCustomer = Document::invoices()
                ->whereIn('customer_id', $customersChunk->pluck('id'))
                ->tap(fn ($q) => $this->applyOutstandingFilters($q, $filters))
                ->orderBy('doc_date')
                ->get()
                ->groupBy('customer_id');

            foreach ($customersChunk as $customer) {
                $invoices = $invoicesByCustomer->get($customer->id, collect())
                    ->map(fn (Document $invoice) => [
                        'doc_date' => $invoice->doc_date?->format('d M Y'),
                        'doc_number' => $invoice->doc_number,
                        'total_value' => (float) $invoice->total_value,
                        'outstanding' => $this->outstandingAmount($invoice),
                    ])->all();

                if (! empty($invoices)) {
                    yield [
                        'company_name' => $customer->company_name,
                        'reference' => (string) $customer->reference,
                        'invoices' => $invoices,
                    ];
                }
            }

            $last = $customersChunk->last();
            $cursor = [$last->company_name, $last->id];
        }
    }

    /**
     * Materializes the full result of exportChunks() into an array. Use this
     * only when a caller genuinely needs the whole dataset in memory at once
     * (e.g. PDF rendering); callers that can process one customer at a time
     * should call exportChunks() directly to stay memory-bounded.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>
     */
    public function buildExportData(array $filters): array
    {
        return iterator_to_array($this->exportChunks($filters));
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     */
    public function exportTotalOutstanding(array $data): float
    {
        return array_sum(array_map(
            fn (array $customer) => array_sum(array_column($customer['invoices'], 'outstanding')),
            $data,
        ));
    }

    /**
     * @param  array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}  $customer
     * @return array<int, array{0: array<int, string>, 1: bool}>
     */
    protected function customerRows(array $customer): array
    {
        $rows = [];
        $customerTotal = 0.0;
        $customerOutstanding = 0.0;

        foreach ($customer['invoices'] as $invoice) {
            $rows[] = [[
                $customer['company_name'],
                $customer['reference'],
                $invoice['doc_date'] ?? '',
                $invoice['doc_number'],
                number_format($invoice['total_value'], 2, '.', ''),
                number_format($invoice['outstanding'], 2, '.', ''),
            ], false];

            $customerTotal += $invoice['total_value'];
            $customerOutstanding += $invoice['outstanding'];
        }

        $rows[] = [[
            '', '', '', '',
            number_format($customerTotal, 2, '.', ''),
            number_format($customerOutstanding, 2, '.', ''),
        ], true];

        return $rows;
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     * @return array<int, array{0: array<int, string>, 1: bool}>
     */
    protected function exportRows(array $data): array
    {
        $rows = [];

        foreach ($data as $customer) {
            $rows = array_merge($rows, $this->customerRows($customer));
        }

        return $rows;
    }

    /**
     * @return array{customerCount: int, totalOutstanding: float}
     */
    public function writeCsvToPath(string $path, \Generator $chunks, ?callable $onChunk = null): array
    {
        $writer = CsvWriter::createFromPath($path, 'w');
        $writer->insertOne(self::EXPORT_HEADINGS);

        $customerCount = 0;
        $totalOutstanding = 0.0;

        foreach ($chunks as $customer) {
            foreach ($this->customerRows($customer) as [$row]) {
                $writer->insertOne($row);
            }

            $customerCount++;
            $totalOutstanding += array_sum(array_column($customer['invoices'], 'outstanding'));

            if ($onChunk !== null) {
                $onChunk($customer);
            }
        }

        return ['customerCount' => $customerCount, 'totalOutstanding' => $totalOutstanding];
    }

    /**
     * @return array{customerCount: int, totalOutstanding: float}
     */
    public function writeXlsxToPath(string $path, \Generator $chunks, ?callable $onChunk = null): array
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $boldStyle = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle(self::EXPORT_HEADINGS, $boldStyle));

        $customerCount = 0;
        $totalOutstanding = 0.0;

        try {
            foreach ($chunks as $customer) {
                foreach ($this->customerRows($customer) as [$row, $isSubtotal]) {
                    $writer->addRow($isSubtotal ? Row::fromValuesWithStyle($row, $boldStyle) : Row::fromValues($row));
                }

                $customerCount++;
                $totalOutstanding += array_sum(array_column($customer['invoices'], 'outstanding'));

                if ($onChunk !== null) {
                    $onChunk($customer);
                }
            }
        } finally {
            $writer->close();
        }

        return ['customerCount' => $customerCount, 'totalOutstanding' => $totalOutstanding];
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     */
    public function streamCsv(array $data): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $writer = CsvWriter::createFromStream(fopen('php://output', 'w'));
            $writer->insertOne(self::EXPORT_HEADINGS);

            foreach ($this->exportRows($data) as [$row]) {
                $writer->insertOne($row);
            }
        }, 'customer-outstanding-payments.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     */
    protected function csvBinary(array $data): string
    {
        $writer = CsvWriter::createFromString();
        $writer->insertOne(self::EXPORT_HEADINGS);

        foreach ($this->exportRows($data) as [$row]) {
            $writer->insertOne($row);
        }

        return $writer->toString();
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     */
    protected function writeXlsx(XlsxWriter $writer, array $data): void
    {
        $boldStyle = (new Style)->withFontBold(true);

        $writer->addRow(Row::fromValuesWithStyle(self::EXPORT_HEADINGS, $boldStyle));

        foreach ($this->exportRows($data) as [$row, $isSubtotal]) {
            $writer->addRow($isSubtotal ? Row::fromValuesWithStyle($row, $boldStyle) : Row::fromValues($row));
        }
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     */
    public function streamXlsx(array $data): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');

            try {
                $this->writeXlsx($writer, $data);
            } finally {
                $writer->close();
            }
        }, 'customer-outstanding-payments.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     */
    protected function xlsxBinary(array $data): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx');

        $writer = new XlsxWriter;
        $writer->openToFile($tmpPath);

        try {
            $this->writeXlsx($writer, $data);
        } finally {
            $writer->close();
        }

        $contents = file_get_contents($tmpPath);
        unlink($tmpPath);

        return $contents;
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     */
    public function invoiceRowCount(array $data): int
    {
        return array_sum(array_map(fn (array $customer) => count($customer['invoices']), $data));
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     */
    public function pdfBinary(array $data): string
    {
        return Pdf::loadView('pdfs.customer-outstanding', ['customers' => $data])
            ->setOption('isPhpEnabled', true)
            ->output();
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{doc_date: ?string, doc_number: string, total_value: float, outstanding: float}>}>  $data
     */
    public function streamPdf(array $data, bool $inline = false): Response
    {
        $pdf = Pdf::loadView('pdfs.customer-outstanding', ['customers' => $data])
            ->setOption('isPhpEnabled', true);

        return $inline
            ? $pdf->stream('customer-outstanding-payments.pdf')
            : $pdf->download('customer-outstanding-payments.pdf');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $emails
     * @param  array<int, string>  $formats
     */
    public function sendReport(array $filters, array $emails, array $formats, ?string $notes = null): void
    {
        $data = $this->buildExportData($filters);

        $totalOutstanding = $this->exportTotalOutstanding($data);

        $attachments = [];

        if (in_array('pdf', $formats, true)) {
            $attachments[] = [
                'data' => $this->pdfBinary($data),
                'filename' => 'customer-outstanding-payments.pdf',
                'mime' => 'application/pdf',
            ];
        }

        if (in_array('csv', $formats, true)) {
            $attachments[] = [
                'data' => $this->csvBinary($data),
                'filename' => 'customer-outstanding-payments.csv',
                'mime' => 'text/csv',
            ];
        }

        if (in_array('xlsx', $formats, true)) {
            $attachments[] = [
                'data' => $this->xlsxBinary($data),
                'filename' => 'customer-outstanding-payments.xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        Mail::to($emails)->send(new CustomerOutstandingReportMail(
            count($data),
            $totalOutstanding,
            $notes,
            $attachments,
        ));
    }
}
