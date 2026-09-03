<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Document;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
use League\Csv\Writer as CsvWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;

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

    public const OUTSTANDING_EXPR = '(documents.total_value
        - COALESCE((select sum(pa.allocated_amount) from payment_allocations pa where pa.document_id = documents.id and pa.deleted_at is null), 0)
        - COALESCE((select sum(ca.amount) from credit_allocations ca where ca.invoice_id = documents.id and ca.deleted_at is null), 0)
        - COALESCE((select sum(wo.amount) from write_offs wo where wo.document_id = documents.id and wo.deleted_at is null), 0))';

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyOutstandingFilters(Builder $query, array $filters): Builder
    {
        $showPaid = (bool) ($filters['showPaid'] ?? false);

        return $query
            ->withSum('paymentAllocations as allocated_total', 'allocated_amount')
            ->withSum('creditAllocationsReceived as credited_total', 'amount')
            ->withSum('writeOffs as written_off_total', 'amount')
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '<=', $date))
            ->when(is_numeric($filters['amountMin'] ?? null), fn ($q) => $q->where('total_value', '>=', (float) $filters['amountMin']))
            ->when(is_numeric($filters['amountMax'] ?? null), fn ($q) => $q->where('total_value', '<=', (float) $filters['amountMax']))
            ->where(function ($q) use ($showPaid) {
                $q->where(function ($q2) {
                    $q2->whereRaw(self::OUTSTANDING_EXPR.' > 0.001')->whereDoesntHave('writeOffs');
                });

                if ($showPaid) {
                    $q->orWhereHas('writeOffs');
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
            ->with(['invoices' => fn ($q) => $this->applyOutstandingFilters($q, $filters)->with('writeOffs.writtenOffBy')->orderByDesc('doc_date')->orderByDesc('doc_number')])
            ->orderBy('company_name');
    }

    public function outstandingAmount(Document $invoice): float
    {
        return (float) $invoice->total_value
            - (float) ($invoice->allocated_total ?? 0)
            - (float) ($invoice->credited_total ?? 0)
            - (float) ($invoice->written_off_total ?? 0);
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
                ->orderByDesc('doc_date')
                ->orderByDesc('doc_number')
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
     * Generates one export file to disk for the given format, using the same
     * memory-bounded path as the download/email flows (never materializes a
     * full CSV/XLSX row set at once; PDF is capped since dompdf can't stream).
     *
     * @param  array<string, mixed>  $filters
     * @return array{customerCount: int, totalOutstanding: float}
     */
    public function generateExportFile(string $format, array $filters, string $absPath, ?callable $onChunk = null): array
    {
        return match ($format) {
            'csv' => $this->writeCsvToPath($absPath, $this->exportChunks($filters), $onChunk),
            'xlsx' => $this->writeXlsxToPath($absPath, $this->exportChunks($filters), $onChunk),
            'pdf' => $this->generatePdfFile($filters, $absPath, $onChunk),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{customerCount: int, totalOutstanding: float}
     */
    protected function generatePdfFile(array $filters, string $absPath, ?callable $onChunk = null): array
    {
        $data = $this->buildExportData($filters);

        if ($this->invoiceRowCount($data) > self::PDF_ROW_CAP) {
            throw new \RuntimeException(
                'PDF export exceeds '.self::PDF_ROW_CAP.' invoice rows; use CSV or Excel instead.'
            );
        }

        $binary = $this->pdfBinary($data);

        if ($onChunk !== null) {
            $onChunk();
        }

        file_put_contents($absPath, $binary);

        return ['customerCount' => count($data), 'totalOutstanding' => $this->exportTotalOutstanding($data)];
    }
}
