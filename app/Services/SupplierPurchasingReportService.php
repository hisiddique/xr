<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\SupplierInvoiceStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
use League\Csv\Writer as CsvWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;

class SupplierPurchasingReportService
{
    /** @var array<int, string> */
    protected const EXPORT_HEADINGS = ['Supplier', 'Reference', 'Date', 'Supplier Invoice No', 'Invoice', 'Net', 'VAT', 'Gross', 'Paid Status'];

    protected const EXPORT_CHUNK_SIZE = 200;

    /**
     * dompdf cannot stream — it must hold the fully-rendered document in memory
     * regardless of how the data is built. Above this many invoice rows the
     * render is both slow and memory-risky on shared hosting, so PDF exports
     * are rejected in favour of CSV/XLSX rather than attempting them.
     */
    public const PDF_ROW_CAP = 5000;

    protected const NET_EXPR = '(select coalesce(sum(sii.line_total),0) from supplier_invoice_items sii where sii.supplier_invoice_id = supplier_invoices.id and sii.deleted_at is null)';

    protected const VAT_EXPR = '(select coalesce(sum(case when sii.vat_applicable then round(sii.line_total * ? / 100, 2) else 0 end),0) from supplier_invoice_items sii where sii.supplier_invoice_id = supplier_invoices.id and sii.deleted_at is null)';

    protected const GROSS_EXPR = '('.self::NET_EXPR.' + '.self::VAT_EXPR.')';

    /**
     * Unclamped outstanding amount. Negative (overpaid) values never satisfy
     * the "partial"/"unpaid" boundaries below, and the "paid" boundary
     * (<= 0) matches the model's clamped `outstandingAmount` accessor for
     * every value it can produce, so no GREATEST/CASE clamp is needed here.
     */
    protected const PAID_EXPR = '('.self::GROSS_EXPR.'
        - COALESCE((select sum(spa.allocated_amount) from supplier_payout_allocations spa where spa.supplier_invoice_id = supplier_invoices.id), 0)
        - COALESCE((select sum(sidn.applied_amount) from supplier_invoice_debit_notes sidn where sidn.supplier_invoice_id = supplier_invoices.id), 0))';

    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '%_');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $vatRate = (float) Setting::get('vat_rate', 20);
        $paidStatus = $filters['paidStatus'] ?? null;

        return $query
            ->where('status', SupplierInvoiceStatus::Posted->value)
            ->when($filters['supplierId'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->whereDate('invoice_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->whereDate('invoice_date', '<=', $date))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$this->escapeLike($search).'%';

                $q->where(function ($q2) use ($like) {
                    $q2->where('supplier_invoice_no', 'like', $like)
                        ->orWhere('supplier_ref_invoice_no', 'like', $like)
                        ->orWhereHas('supplier', fn ($q3) => $q3->where('company_name', 'like', $like)
                            ->orWhere('reference', 'like', $like));
                });
            })
            ->when(is_numeric($filters['amountMin'] ?? null), fn ($q) => $q->whereRaw(self::GROSS_EXPR.' >= CAST(? AS REAL)', [$vatRate, (float) $filters['amountMin']]))
            ->when(is_numeric($filters['amountMax'] ?? null), fn ($q) => $q->whereRaw(self::GROSS_EXPR.' <= CAST(? AS REAL)', [$vatRate, (float) $filters['amountMax']]))
            ->when($paidStatus === 'paid', fn ($q) => $q->whereRaw(self::PAID_EXPR.' <= 0.001', [$vatRate]))
            ->when($paidStatus === 'unpaid', fn ($q) => $q->whereRaw(self::PAID_EXPR.' >= '.self::GROSS_EXPR.' - 0.001', [$vatRate, $vatRate]))
            ->when($paidStatus === 'partial', function ($q) use ($vatRate) {
                $q->whereRaw(self::PAID_EXPR.' > 0.001', [$vatRate])
                    ->whereRaw(self::PAID_EXPR.' < '.self::GROSS_EXPR.' - 0.001', [$vatRate, $vatRate]);
            });
    }

    /**
     * Filtered-set aggregate for the report's stat cards. Uses the same SQL
     * expressions as the filters themselves so the cards always match what
     * amountMin/amountMax/paidStatus actually let through, without
     * materializing the full filtered result set like buildExportData() does.
     *
     * @param  array<string, mixed>  $filters
     * @return array{invoiceCount: int, totalNet: float, totalVat: float, totalGross: float}
     */
    public function summary(array $filters): array
    {
        $vatRate = (float) Setting::get('vat_rate', 20);

        $row = SupplierInvoice::query()
            ->tap(fn ($q) => $this->applyFilters($q, $filters))
            ->selectRaw(
                'count(*) as cnt, coalesce(sum('.self::NET_EXPR.'),0) as net, coalesce(sum('.self::VAT_EXPR.'),0) as vat, coalesce(sum('.self::GROSS_EXPR.'),0) as gross',
                [$vatRate, $vatRate]
            )
            ->first();

        return [
            'invoiceCount' => (int) $row->cnt,
            'totalNet' => (float) $row->net,
            'totalVat' => (float) $row->vat,
            'totalGross' => (float) $row->gross,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function invoicesQuery(array $filters): Builder
    {
        return SupplierInvoice::query()
            ->select('supplier_invoices.*')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_invoices.supplier_id')
            ->tap(fn ($q) => $this->applyFilters($q, $filters))
            ->with(['supplier', 'items', 'payoutAllocations', 'debitNotes'])
            ->orderBy('suppliers.company_name')
            ->orderByDesc('supplier_invoices.invoice_date')
            ->orderByDesc('supplier_invoices.supplier_invoice_no');
    }

    /**
     * Columns the report's table headers may sort by. 'company_name' sorts
     * the supplier group order; every other key sorts the invoices within
     * each group (group order stays alphabetical in that case) since a
     * grouped table has no single meaningful "global" row order otherwise.
     *
     * @param  array<string, mixed>  $filters
     */
    public function suppliersQuery(array $filters): Builder
    {
        $sortBy = in_array($filters['sortBy'] ?? null, ['company_name', 'invoice_date', 'supplier_invoice_no', 'net', 'vat', 'gross', 'paid_status'], true)
            ? $filters['sortBy']
            : 'company_name';
        $sortDirection = ($filters['sortDirection'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query = Supplier::query()
            ->whereHas('supplierInvoices', fn ($q) => $this->applyFilters($q, $filters))
            ->when($filters['supplierId'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->with(['supplierInvoices' => function ($q) use ($filters, $sortBy, $sortDirection) {
                $this->applyFilters($q, $filters)->with(['items', 'payoutAllocations', 'debitNotes']);
                $this->applyInvoiceSort($q, $sortBy, $sortDirection);
            }]);

        return $sortBy === 'company_name'
            ? $query->orderBy('company_name', $sortDirection)
            : $query->orderBy('company_name');
    }

    protected function applyInvoiceSort(Builder $query, string $sortBy, string $direction): void
    {
        $vatRate = (float) Setting::get('vat_rate', 20);

        match ($sortBy) {
            'invoice_date' => $query->orderBy('invoice_date', $direction)->orderBy('supplier_invoice_no', $direction),
            'supplier_invoice_no' => $query->orderBy('supplier_invoice_no', $direction),
            'net' => $query->orderByRaw(self::NET_EXPR.' '.$direction),
            'vat' => $query->orderByRaw(self::VAT_EXPR.' '.$direction, [$vatRate]),
            'gross' => $query->orderByRaw(self::GROSS_EXPR.' '.$direction, [$vatRate]),
            'paid_status' => $query->orderByRaw(self::PAID_EXPR.' '.$direction, [$vatRate]),
            default => $query->orderByDesc('invoice_date')->orderByDesc('supplier_invoice_no'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function exportSuppliersQuery(array $filters): Builder
    {
        return Supplier::query()
            ->whereHas('supplierInvoices', fn ($q) => $this->applyFilters($q, $filters))
            ->when($filters['supplierId'] ?? null, fn ($q, $id) => $q->where('id', $id));
    }

    public function outstandingAmount(SupplierInvoice $invoice): float
    {
        return (float) $invoice->outstandingAmount;
    }

    public function paidStatus(SupplierInvoice $invoice): string
    {
        $outstanding = $this->outstandingAmount($invoice);
        $gross = (float) $invoice->grossTotal;

        return match (true) {
            $outstanding <= 0.001 => 'paid',
            $outstanding >= $gross - 0.001 => 'unpaid',
            default => 'partial',
        };
    }

    /**
     * Yield export rows one supplier at a time using keyset pagination on
     * (company_name, id) instead of chunkById, so ordering is preserved
     * without a final in-memory sort and peak memory stays bounded regardless
     * of total dataset size.
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, array{company_name: string, reference: string, invoices: array<int, array{invoice_date: ?string, supplier_invoice_no: string, supplier_ref_invoice_no: string, net: float, vat: float, gross: float, paid_status: string}>}>
     */
    public function exportChunks(array $filters): \Generator
    {
        $cursor = null;

        while (true) {
            $query = $this->exportSuppliersQuery($filters)
                ->reorder()
                ->select('suppliers.id', 'suppliers.company_name', 'suppliers.reference')
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

            $suppliersChunk = $query->limit(self::EXPORT_CHUNK_SIZE)->get();

            if ($suppliersChunk->isEmpty()) {
                return;
            }

            $invoicesBySupplier = SupplierInvoice::query()
                ->whereIn('supplier_id', $suppliersChunk->pluck('id'))
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->with(['items', 'payoutAllocations', 'debitNotes'])
                ->orderByDesc('invoice_date')
                ->orderByDesc('supplier_invoice_no')
                ->get()
                ->groupBy('supplier_id');

            foreach ($suppliersChunk as $supplier) {
                $invoices = $invoicesBySupplier->get($supplier->id, collect())
                    ->map(fn (SupplierInvoice $invoice) => [
                        'invoice_date' => $invoice->invoice_date?->format('d M Y'),
                        'supplier_invoice_no' => $invoice->supplier_invoice_no,
                        'supplier_ref_invoice_no' => (string) $invoice->supplier_ref_invoice_no,
                        'net' => $invoice->netTotal,
                        'vat' => $invoice->vatTotal,
                        'gross' => $invoice->grossTotal,
                        'paid_status' => $this->paidStatus($invoice),
                    ])->all();

                if (! empty($invoices)) {
                    yield [
                        'company_name' => $supplier->company_name,
                        'reference' => (string) $supplier->reference,
                        'invoices' => $invoices,
                    ];
                }
            }

            $last = $suppliersChunk->last();
            $cursor = [$last->company_name, $last->id];
        }
    }

    /**
     * Materializes the full result of exportChunks() into an array. Use this
     * only when a caller genuinely needs the whole dataset in memory at once
     * (e.g. PDF rendering); callers that can process one supplier at a time
     * should call exportChunks() directly to stay memory-bounded.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{company_name: string, reference: string, invoices: array<int, array{invoice_date: ?string, supplier_invoice_no: string, supplier_ref_invoice_no: string, net: float, vat: float, gross: float, paid_status: string}>}>
     */
    public function buildExportData(array $filters): array
    {
        return iterator_to_array($this->exportChunks($filters));
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{invoice_date: ?string, supplier_invoice_no: string, supplier_ref_invoice_no: string, net: float, vat: float, gross: float, paid_status: string}>}>  $data
     * @return array{invoiceCount: int, totalNet: float, totalVat: float, totalGross: float}
     */
    public function exportSummary(array $data): array
    {
        $invoiceCount = 0;
        $totalNet = 0.0;
        $totalVat = 0.0;
        $totalGross = 0.0;

        foreach ($data as $supplier) {
            $invoiceCount += count($supplier['invoices']);
            $totalNet += array_sum(array_column($supplier['invoices'], 'net'));
            $totalVat += array_sum(array_column($supplier['invoices'], 'vat'));
            $totalGross += array_sum(array_column($supplier['invoices'], 'gross'));
        }

        return [
            'invoiceCount' => $invoiceCount,
            'totalNet' => $totalNet,
            'totalVat' => $totalVat,
            'totalGross' => $totalGross,
        ];
    }

    /**
     * @param  array{company_name: string, reference: string, invoices: array<int, array{invoice_date: ?string, supplier_invoice_no: string, supplier_ref_invoice_no: string, net: float, vat: float, gross: float, paid_status: string}>}  $supplier
     * @return array<int, array{0: array<int, string>, 1: bool}>
     */
    protected function supplierRows(array $supplier): array
    {
        $rows = [];
        $net = 0.0;
        $vat = 0.0;
        $gross = 0.0;

        foreach ($supplier['invoices'] as $invoice) {
            $rows[] = [[
                $supplier['company_name'],
                $supplier['reference'],
                $invoice['invoice_date'] ?? '',
                $invoice['supplier_invoice_no'],
                $invoice['supplier_ref_invoice_no'],
                number_format($invoice['net'], 2, '.', ''),
                number_format($invoice['vat'], 2, '.', ''),
                number_format($invoice['gross'], 2, '.', ''),
                ucfirst($invoice['paid_status']),
            ], false];

            $net += $invoice['net'];
            $vat += $invoice['vat'];
            $gross += $invoice['gross'];
        }

        $rows[] = [[
            '', '', '', '', '',
            number_format($net, 2, '.', ''),
            number_format($vat, 2, '.', ''),
            number_format($gross, 2, '.', ''),
            '',
        ], true];

        return $rows;
    }

    /**
     * @return array{invoiceCount: int, totalNet: float, totalVat: float, totalGross: float}
     */
    public function writeCsvToPath(string $path, \Generator $chunks, ?callable $onChunk = null): array
    {
        $writer = CsvWriter::createFromPath($path, 'w');
        $writer->insertOne(self::EXPORT_HEADINGS);

        $invoiceCount = 0;
        $totalNet = 0.0;
        $totalVat = 0.0;
        $totalGross = 0.0;

        foreach ($chunks as $supplier) {
            foreach ($this->supplierRows($supplier) as [$row]) {
                $writer->insertOne($row);
            }

            $invoiceCount += count($supplier['invoices']);
            $totalNet += array_sum(array_column($supplier['invoices'], 'net'));
            $totalVat += array_sum(array_column($supplier['invoices'], 'vat'));
            $totalGross += array_sum(array_column($supplier['invoices'], 'gross'));

            if ($onChunk !== null) {
                $onChunk($supplier);
            }
        }

        return ['invoiceCount' => $invoiceCount, 'totalNet' => $totalNet, 'totalVat' => $totalVat, 'totalGross' => $totalGross];
    }

    /**
     * @return array{invoiceCount: int, totalNet: float, totalVat: float, totalGross: float}
     */
    public function writeXlsxToPath(string $path, \Generator $chunks, ?callable $onChunk = null): array
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $boldStyle = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle(self::EXPORT_HEADINGS, $boldStyle));

        $invoiceCount = 0;
        $totalNet = 0.0;
        $totalVat = 0.0;
        $totalGross = 0.0;

        try {
            foreach ($chunks as $supplier) {
                foreach ($this->supplierRows($supplier) as [$row, $isSubtotal]) {
                    $writer->addRow($isSubtotal ? Row::fromValuesWithStyle($row, $boldStyle) : Row::fromValues($row));
                }

                $invoiceCount += count($supplier['invoices']);
                $totalNet += array_sum(array_column($supplier['invoices'], 'net'));
                $totalVat += array_sum(array_column($supplier['invoices'], 'vat'));
                $totalGross += array_sum(array_column($supplier['invoices'], 'gross'));

                if ($onChunk !== null) {
                    $onChunk($supplier);
                }
            }
        } finally {
            $writer->close();
        }

        return ['invoiceCount' => $invoiceCount, 'totalNet' => $totalNet, 'totalVat' => $totalVat, 'totalGross' => $totalGross];
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{invoice_date: ?string, supplier_invoice_no: string, supplier_ref_invoice_no: string, net: float, vat: float, gross: float, paid_status: string}>}>  $data
     */
    public function invoiceRowCount(array $data): int
    {
        return array_sum(array_map(fn (array $supplier) => count($supplier['invoices']), $data));
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{invoice_date: ?string, supplier_invoice_no: string, supplier_ref_invoice_no: string, net: float, vat: float, gross: float, paid_status: string}>}>  $data
     */
    public function pdfBinary(array $data): string
    {
        return Pdf::loadView('pdfs.supplier-purchasing', ['suppliers' => $data])
            ->setOption('isPhpEnabled', true)
            ->output();
    }

    /**
     * @param  array<int, array{company_name: string, reference: string, invoices: array<int, array{invoice_date: ?string, supplier_invoice_no: string, supplier_ref_invoice_no: string, net: float, vat: float, gross: float, paid_status: string}>}>  $data
     */
    public function streamPdf(array $data, bool $inline = false): Response
    {
        $pdf = Pdf::loadView('pdfs.supplier-purchasing', ['suppliers' => $data])
            ->setOption('isPhpEnabled', true);

        return $inline
            ? $pdf->stream('supplier-purchasing-report.pdf')
            : $pdf->download('supplier-purchasing-report.pdf');
    }

    /**
     * Generates one export file to disk for the given format, using the same
     * memory-bounded path as the download/email flows (never materializes a
     * full CSV/XLSX row set at once; PDF is capped since dompdf can't stream).
     *
     * @param  array<string, mixed>  $filters
     * @return array{invoiceCount: int, totalNet: float, totalVat: float, totalGross: float}
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
     * @return array{invoiceCount: int, totalNet: float, totalVat: float, totalGross: float}
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

        return $this->exportSummary($data);
    }
}
