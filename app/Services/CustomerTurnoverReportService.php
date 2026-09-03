<?php

namespace App\Services;

use App\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer as CsvWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;

class CustomerTurnoverReportService
{
    protected const EXPORT_CHUNK_SIZE = 200;

    /**
     * dompdf cannot stream — it must hold the fully-rendered document in memory
     * regardless of how the data is built. Turnover rows are one-per-customer so
     * this cap is generous, but it still guards against a runaway render on
     * shared hosting.
     */
    public const PDF_ROW_CAP = 5000;

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyPeriod(Builder $q, array $filters): Builder
    {
        return $q
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('doc_date', '<=', $date));
    }

    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '%_');
    }

    /**
     * Filter customers by their period-scoped turnover total. Uses a raw
     * correlated scalar subquery in a WHERE (not HAVING — SQLite treats a
     * HAVING without GROUP BY as a single-group aggregate and collapses the
     * result set). whereRaw with explicit ordered bindings is used rather than
     * a where(Closure) sub-select: the latter's bindings interleave wrongly
     * with the query's own withCount/withSum/selectSub bindings. Mirrors the
     * osMin/osMax pattern in CustomerOutstandingReportService.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyTotalRange(Builder $query, array $filters): Builder
    {
        $dateSql = '';

        // Everything in this fragment is inlined — no ? placeholders. A whereRaw
        // that carries its own bindings interleaves wrongly with the query's
        // withCount/withSum select-subquery bindings and a sibling where()
        // binding under bound execution on SQLite: toSql()/getBindings()/
        // toRawSql() all look correct, yet PDO returns zero rows. Dates are
        // validated to YYYY-MM-DD; the bounds are cast to float — both are
        // injection-safe to inline.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['dateFrom'] ?? ''))) {
            $dateSql .= " AND date(documents.doc_date) >= '{$filters['dateFrom']}'";
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['dateTo'] ?? ''))) {
            $dateSql .= " AND date(documents.doc_date) <= '{$filters['dateTo']}'";
        }

        $sumExpr = '(SELECT COALESCE(SUM(total_value), 0) FROM documents'
            ." WHERE documents.customer_id = customers.id AND documents.type = 'INV'"
            ." AND documents.deleted_at IS NULL{$dateSql})";

        return $query
            ->when(is_numeric($filters['totalMin'] ?? null), fn ($q) => $q->whereRaw($sumExpr.' >= '.sprintf('%.4F', (float) $filters['totalMin'])))
            ->when(is_numeric($filters['totalMax'] ?? null), fn ($q) => $q->whereRaw($sumExpr.' <= '.sprintf('%.4F', (float) $filters['totalMax'])));
    }

    /**
     * Correlated subquery over `documents` (kept aliased as `documents` so the
     * shared OUTSTANDING_EXPR resolves) that sums the period-scoped outstanding
     * balance for one customer.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function outstandingSubquery(array $filters): \Illuminate\Database\Query\Builder
    {
        return DB::table('documents')
            ->selectRaw('coalesce(sum('.CustomerOutstandingReportService::OUTSTANDING_EXPR.'), 0)')
            ->whereColumn('documents.customer_id', 'customers.id')
            ->where('documents.type', 'INV')
            ->whereNull('documents.deleted_at')
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('documents.doc_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('documents.doc_date', '<=', $date));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function customersQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $includeOutstanding = ! empty($filters['includeOutstanding']);

        $query = Customer::query()
            ->select('customers.id', 'customers.company_name', 'customers.reference')
            ->withCount(['invoices as invoice_count' => fn ($q) => $this->applyPeriod($q, $filters)])
            ->withSum(['invoices as total' => fn ($q) => $this->applyPeriod($q, $filters)], 'total_value')
            ->whereHas('invoices', fn ($q) => $this->applyPeriod($q, $filters))
            ->when($filters['customerId'] ?? null, fn ($q, $id) => $q->where('customers.id', $id))
            ->when($search !== '', function ($q) use ($search, $filters) {
                $like = '%'.$this->escapeLike($search).'%';

                $q->where(function ($q) use ($like, $filters) {
                    $q->where('company_name', 'like', $like)
                        ->orWhere('reference', 'like', $like)
                        ->orWhereHas('invoices', fn ($q) => $this->applyPeriod($q, $filters)
                            ->where('doc_number', 'like', $like));
                });
            })
            ->tap(fn ($q) => $this->applyTotalRange($q, $filters));

        if ($includeOutstanding) {
            $query->selectSub($this->outstandingSubquery($filters), 'outstanding');
        }

        return $this->applySort($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applySort(Builder $query, array $filters): Builder
    {
        $column = (string) ($filters['sortColumn'] ?? '');
        $direction = strtolower((string) ($filters['sortDirection'] ?? '')) === 'asc' ? 'asc' : 'desc';
        $includeOutstanding = ! empty($filters['includeOutstanding']);

        $query = match ($column) {
            'company_name' => $query->orderBy('company_name', $direction),
            'invoice_count' => $query->orderBy('invoice_count', $direction),
            'total' => $query->orderBy('total', $direction),
            'outstanding' => $includeOutstanding ? $query->orderBy('outstanding', $direction) : $query->orderByDesc('total'),
            default => $query->orderByDesc('total'),
        };

        return $query->orderBy('customers.id');
    }

    public function outstandingHeading(): string
    {
        return 'Outstanding';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    public function exportHeadings(array $filters): array
    {
        $headings = ['Customer', 'Reference', 'Invoices', 'Total'];

        if (! empty($filters['includeOutstanding'])) {
            $headings[] = $this->outstandingHeading();
        }

        return $headings;
    }

    /**
     * Yield one flat row per customer using keyset pagination on
     * (company_name, customers.id) so ordering is preserved without a final
     * in-memory sort and peak memory stays bounded regardless of dataset size.
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, array{company_name: string, reference: string, invoice_count: int, total: float, outstanding: float}>
     */
    public function exportChunks(array $filters): \Generator
    {
        $includeOutstanding = ! empty($filters['includeOutstanding']);
        $cursor = null;

        while (true) {
            $query = $this->customersQuery($filters)
                ->reorder()
                ->orderBy('company_name')
                ->orderBy('customers.id');

            if ($cursor !== null) {
                [$lastName, $lastId] = $cursor;
                $query->where(function ($q) use ($lastName, $lastId) {
                    $q->where('company_name', '>', $lastName)
                        ->orWhere(function ($q2) use ($lastName, $lastId) {
                            $q2->where('company_name', $lastName)->where('customers.id', '>', $lastId);
                        });
                });
            }

            $customersChunk = $query->limit(self::EXPORT_CHUNK_SIZE)->get();

            if ($customersChunk->isEmpty()) {
                return;
            }

            foreach ($customersChunk as $customer) {
                yield [
                    'company_name' => (string) $customer->company_name,
                    'reference' => (string) $customer->reference,
                    'invoice_count' => (int) ($customer->invoice_count ?? 0),
                    'total' => (float) ($customer->total ?? 0),
                    'outstanding' => $includeOutstanding ? (float) ($customer->outstanding ?? 0) : 0.0,
                ];
            }

            $last = $customersChunk->last();
            $cursor = [$last->company_name, $last->id];
        }
    }

    /**
     * Materializes the full result of exportChunks() into an array. Use this
     * only when a caller genuinely needs the whole dataset in memory at once
     * (e.g. PDF rendering).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{company_name: string, reference: string, invoice_count: int, total: float, outstanding: float}>
     */
    public function buildExportData(array $filters): array
    {
        return iterator_to_array($this->exportChunks($filters));
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoice_count: int, total: float, outstanding: float}>  $data
     */
    public function rowCount(array $data): int
    {
        return count($data);
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoice_count: int, total: float, outstanding: float}>  $data
     * @return array{invoiceCount: int, total: float, outstanding: float}
     */
    public function exportTotals(array $data): array
    {
        return [
            'invoiceCount' => (int) array_sum(array_column($data, 'invoice_count')),
            'total' => (float) array_sum(array_column($data, 'total')),
            'outstanding' => (float) array_sum(array_column($data, 'outstanding')),
        ];
    }

    /**
     * @param  array{company_name: string, reference: string, invoice_count: int, total: float, outstanding: float}  $customer
     * @return array<int, string>
     */
    protected function dataRows(array $customer, bool $includeOutstanding): array
    {
        $row = [
            $customer['company_name'],
            $customer['reference'],
            (string) $customer['invoice_count'],
            number_format($customer['total'], 2, '.', ''),
        ];

        if ($includeOutstanding) {
            $row[] = number_format($customer['outstanding'], 2, '.', '');
        }

        return $row;
    }

    /**
     * @return array<int, string>
     */
    protected function grandTotalRow(int $invoiceCount, float $total, float $outstanding, bool $includeOutstanding): array
    {
        $row = ['TOTAL', '', (string) $invoiceCount, number_format($total, 2, '.', '')];

        if ($includeOutstanding) {
            $row[] = number_format($outstanding, 2, '.', '');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{customerCount: int, total: float, outstanding: float}
     */
    public function writeCsvToPath(string $path, \Generator $chunks, array $filters, ?callable $onChunk = null): array
    {
        $includeOutstanding = ! empty($filters['includeOutstanding']);

        $writer = CsvWriter::createFromPath($path, 'w');
        $writer->insertOne($this->exportHeadings($filters));

        $customerCount = 0;
        $invoiceCount = 0;
        $total = 0.0;
        $outstanding = 0.0;

        foreach ($chunks as $customer) {
            $writer->insertOne($this->dataRows($customer, $includeOutstanding));

            $customerCount++;
            $invoiceCount += $customer['invoice_count'];
            $total += $customer['total'];
            $outstanding += $customer['outstanding'];

            if ($onChunk !== null) {
                $onChunk($customer);
            }
        }

        $writer->insertOne($this->grandTotalRow($invoiceCount, $total, $outstanding, $includeOutstanding));

        return ['customerCount' => $customerCount, 'total' => $total, 'outstanding' => $outstanding];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{customerCount: int, total: float, outstanding: float}
     */
    public function writeXlsxToPath(string $path, \Generator $chunks, array $filters, ?callable $onChunk = null): array
    {
        $includeOutstanding = ! empty($filters['includeOutstanding']);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $boldStyle = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle($this->exportHeadings($filters), $boldStyle));

        $customerCount = 0;
        $invoiceCount = 0;
        $total = 0.0;
        $outstanding = 0.0;

        try {
            foreach ($chunks as $customer) {
                $writer->addRow(Row::fromValues($this->dataRows($customer, $includeOutstanding)));

                $customerCount++;
                $invoiceCount += $customer['invoice_count'];
                $total += $customer['total'];
                $outstanding += $customer['outstanding'];

                if ($onChunk !== null) {
                    $onChunk($customer);
                }
            }

            $writer->addRow(Row::fromValuesWithStyle(
                $this->grandTotalRow($invoiceCount, $total, $outstanding, $includeOutstanding),
                $boldStyle,
            ));
        } finally {
            $writer->close();
        }

        return ['customerCount' => $customerCount, 'total' => $total, 'outstanding' => $outstanding];
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoice_count: int, total: float, outstanding: float}>  $data
     * @param  array<string, mixed>  $filters
     */
    public function pdfBinary(array $data, array $filters): string
    {
        return Pdf::loadView('pdfs.customer-turnover', [
            'rows' => $data,
            'includeOutstanding' => ! empty($filters['includeOutstanding']),
            'totals' => $this->exportTotals($data),
        ])->setOption('isPhpEnabled', true)->output();
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoice_count: int, total: float, outstanding: float}>  $data
     * @param  array<string, mixed>  $filters
     */
    public function streamPdf(array $data, array $filters, bool $inline = false): Response
    {
        $pdf = Pdf::loadView('pdfs.customer-turnover', [
            'rows' => $data,
            'includeOutstanding' => ! empty($filters['includeOutstanding']),
            'totals' => $this->exportTotals($data),
        ])->setOption('isPhpEnabled', true);

        return $inline
            ? $pdf->stream('customer-turnover.pdf')
            : $pdf->download('customer-turnover.pdf');
    }

    /**
     * Generates one export file to disk for the given format, using the same
     * memory-bounded path as the download/email flows (never materializes a
     * full CSV/XLSX row set at once; PDF is capped since dompdf can't stream).
     *
     * @param  array<string, mixed>  $filters
     * @return array{customerCount: int, total: float, outstanding: float}
     */
    public function generateExportFile(string $format, array $filters, string $absPath, ?callable $onChunk = null): array
    {
        return match ($format) {
            'csv' => $this->writeCsvToPath($absPath, $this->exportChunks($filters), $filters, $onChunk),
            'xlsx' => $this->writeXlsxToPath($absPath, $this->exportChunks($filters), $filters, $onChunk),
            'pdf' => $this->generatePdfFile($filters, $absPath, $onChunk),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{customerCount: int, total: float, outstanding: float}
     */
    protected function generatePdfFile(array $filters, string $absPath, ?callable $onChunk = null): array
    {
        $data = $this->buildExportData($filters);

        if ($this->rowCount($data) > self::PDF_ROW_CAP) {
            throw new \RuntimeException(
                'PDF export exceeds '.self::PDF_ROW_CAP.' customer rows; use CSV or Excel instead.'
            );
        }

        $binary = $this->pdfBinary($data, $filters);

        if ($onChunk !== null) {
            $onChunk();
        }

        file_put_contents($absPath, $binary);

        $totals = $this->exportTotals($data);

        return ['customerCount' => count($data), 'total' => $totals['total'], 'outstanding' => $totals['outstanding']];
    }
}
