<?php

namespace App\Services;

use App\Models\Overhead;
use App\Models\Setting;
use App\Support\Search;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer as CsvWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;

class OverheadExportService
{
    protected const EXPORT_CHUNK_SIZE = 200;

    /**
     * dompdf cannot stream — the fully-rendered document is held in memory, so
     * the row count is capped to protect shared hosting.
     */
    public const PDF_ROW_CAP = 5000;

    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '%_');
    }

    protected function vatRate(): float
    {
        return (float) Setting::get('vat_rate', 20);
    }

    protected function isReport(array $filters): bool
    {
        return ($filters['scope'] ?? 'list') === 'report';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function overheadsQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $filterVat = (string) ($filters['filterVat'] ?? '');

        return Overhead::query()
            ->when($search !== '', fn ($q) => Search::filter($q, $search, ['category.name', 'payment_method']))
            ->when($filters['filterCategory'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filterVat === '0' || $filterVat === '1', fn ($q) => $q->where('has_vat', $filterVat === '1'))
            ->when($filters['dateFrom'] ?? '', fn ($q, $date) => $q->where('expense_date', '>=', $date))
            ->when($filters['dateTo'] ?? '', fn ($q, $date) => $q->where('expense_date', '<=', $date))
            ->when(is_numeric($filters['amountMin'] ?? null), fn ($q) => $q->where('amount', '>=', (float) $filters['amountMin']))
            ->when(is_numeric($filters['amountMax'] ?? null), fn ($q) => $q->where('amount', '<=', (float) $filters['amountMax']))
            ->orderByDesc('expense_date')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function rowCount(array $filters): int
    {
        return $this->overheadsQuery($filters)->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, array{date: string, category: string, payment_method: string, vat_status: string, net: float, vat: float, gross: float}>
     */
    protected function transactions(array $filters): \Generator
    {
        $rate = $this->vatRate();

        foreach ($this->overheadsQuery($filters)->with('category')->lazy(self::EXPORT_CHUNK_SIZE) as $overhead) {
            $gross = (float) $overhead->amount;
            $vat = $overhead->has_vat ? $gross * $rate / (100 + $rate) : 0.0;

            yield [
                'date' => $overhead->expense_date->format('d M Y'),
                'category' => (string) ($overhead->category?->name ?? '—'),
                'payment_method' => (string) ($overhead->payment_method ?: '—'),
                'vat_status' => $overhead->has_vat ? 'Inc. VAT' : 'Ex. VAT',
                'net' => $gross - $vat,
                'vat' => $vat,
                'gross' => $gross,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{category: string, net_cost: float, vat_portion: float, non_vat_spend: float, gross_total: float}>
     */
    protected function categorySummary(array $filters): array
    {
        $rate = $this->vatRate();

        return $this->overheadsQuery($filters)
            ->reorder()
            ->select([
                'category_id',
                DB::raw('SUM(CASE WHEN has_vat = 0 THEN amount ELSE 0 END) as non_vat_spend'),
                DB::raw("SUM(CASE WHEN has_vat = 1 THEN amount * {$rate} / (100 + {$rate}) ELSE 0 END) as vat_portion"),
                DB::raw("SUM(CASE WHEN has_vat = 1 THEN amount * 100 / (100 + {$rate}) ELSE 0 END) as net_cost"),
                DB::raw('SUM(amount) as gross_total'),
            ])
            ->groupBy('category_id')
            ->orderByDesc('gross_total')
            ->with('category')
            ->get()
            ->map(fn ($row) => [
                'category' => (string) ($row->category?->name ?? '—'),
                'net_cost' => (float) $row->net_cost,
                'vat_portion' => (float) $row->vat_portion,
                'non_vat_spend' => (float) $row->non_vat_spend,
                'gross_total' => (float) $row->gross_total,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{net_cost: float, vat_portion: float, non_vat_spend: float, gross_total: float}>  $summary
     * @return array{net_cost: float, vat_portion: float, non_vat_spend: float, gross_total: float}
     */
    protected function summaryTotals(array $summary): array
    {
        return [
            'net_cost' => (float) array_sum(array_column($summary, 'net_cost')),
            'vat_portion' => (float) array_sum(array_column($summary, 'vat_portion')),
            'non_vat_spend' => (float) array_sum(array_column($summary, 'non_vat_spend')),
            'gross_total' => (float) array_sum(array_column($summary, 'gross_total')),
        ];
    }

    protected function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * @return array<int, string>
     */
    protected function summaryHeadings(): array
    {
        return ['Category', 'Net Cost (Ex VAT)', 'Reclaimable VAT', 'Non-VAT Spend', 'Total Gross Cost'];
    }

    /**
     * @param  array{category: string, net_cost: float, vat_portion: float, non_vat_spend: float, gross_total: float}  $row
     * @return array<int, string>
     */
    protected function summaryRow(array $row): array
    {
        return [
            $row['category'],
            $this->money($row['net_cost']),
            $this->money($row['vat_portion']),
            $this->money($row['non_vat_spend']),
            $this->money($row['gross_total']),
        ];
    }

    /**
     * @param  array{net_cost: float, vat_portion: float, non_vat_spend: float, gross_total: float}  $totals
     * @return array<int, string>
     */
    protected function summaryTotalRow(array $totals): array
    {
        return $this->summaryRow(['category' => 'TOTAL'] + $totals);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    protected function transactionHeadings(array $filters): array
    {
        return $this->isReport($filters)
            ? ['Date', 'Category', 'VAT Status', 'Net', 'VAT', 'Gross']
            : ['Date', 'Category', 'Payment Method', 'VAT Status', 'Net', 'VAT', 'Gross'];
    }

    /**
     * @param  array{date: string, category: string, payment_method: string, vat_status: string, net: float, vat: float, gross: float}  $row
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    protected function transactionRow(array $row, array $filters): array
    {
        $amounts = [$this->money($row['net']), $this->money($row['vat']), $this->money($row['gross'])];

        return $this->isReport($filters)
            ? [$row['date'], $row['category'], $row['vat_status'], ...$amounts]
            : [$row['date'], $row['category'], $row['payment_method'], $row['vat_status'], ...$amounts];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    protected function transactionTotalRow(float $net, float $vat, float $gross, array $filters): array
    {
        $amounts = [$this->money($net), $this->money($vat), $this->money($gross)];

        return $this->isReport($filters)
            ? ['TOTAL', '', '', ...$amounts]
            : ['TOTAL', '', '', '', ...$amounts];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rowCount: int, gross: float}
     */
    protected function writeCsvToPath(string $path, array $filters, ?callable $onChunk = null): array
    {
        $writer = CsvWriter::createFromPath($path, 'w');

        if ($this->isReport($filters)) {
            $summary = $this->categorySummary($filters);
            $writer->insertOne($this->summaryHeadings());
            foreach ($summary as $row) {
                $writer->insertOne($this->summaryRow($row));
            }
            $writer->insertOne($this->summaryTotalRow($this->summaryTotals($summary)));
            $writer->insertOne(['']);
        }

        $writer->insertOne($this->transactionHeadings($filters));

        $count = 0;
        $net = $vat = $gross = 0.0;

        foreach ($this->transactions($filters) as $row) {
            $writer->insertOne($this->transactionRow($row, $filters));

            $count++;
            $net += $row['net'];
            $vat += $row['vat'];
            $gross += $row['gross'];

            if ($onChunk !== null) {
                $onChunk($row);
            }
        }

        $writer->insertOne($this->transactionTotalRow($net, $vat, $gross, $filters));

        return ['rowCount' => $count, 'gross' => $gross];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rowCount: int, gross: float}
     */
    protected function writeXlsxToPath(string $path, array $filters, ?callable $onChunk = null): array
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $boldStyle = (new Style)->withFontBold(true);

        $count = 0;
        $net = $vat = $gross = 0.0;

        try {
            if ($this->isReport($filters)) {
                $writer->getCurrentSheet()->setName('Category Summary');

                $summary = $this->categorySummary($filters);
                $writer->addRow(Row::fromValuesWithStyle($this->summaryHeadings(), $boldStyle));
                foreach ($summary as $row) {
                    $writer->addRow(Row::fromValues($this->summaryRow($row)));
                }
                $writer->addRow(Row::fromValuesWithStyle($this->summaryTotalRow($this->summaryTotals($summary)), $boldStyle));

                $writer->addNewSheetAndMakeItCurrent()->setName('Transactions');
            }

            $writer->addRow(Row::fromValuesWithStyle($this->transactionHeadings($filters), $boldStyle));

            foreach ($this->transactions($filters) as $row) {
                $writer->addRow(Row::fromValues($this->transactionRow($row, $filters)));

                $count++;
                $net += $row['net'];
                $vat += $row['vat'];
                $gross += $row['gross'];

                if ($onChunk !== null) {
                    $onChunk($row);
                }
            }

            $writer->addRow(Row::fromValuesWithStyle($this->transactionTotalRow($net, $vat, $gross, $filters), $boldStyle));
        } finally {
            $writer->close();
        }

        return ['rowCount' => $count, 'gross' => $gross];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function pdfData(array $filters): array
    {
        $rows = iterator_to_array($this->transactions($filters), false);
        $summary = $this->isReport($filters) ? $this->categorySummary($filters) : [];

        return [
            'isReport' => $this->isReport($filters),
            'rows' => $rows,
            'totals' => [
                'net' => (float) array_sum(array_column($rows, 'net')),
                'vat' => (float) array_sum(array_column($rows, 'vat')),
                'gross' => (float) array_sum(array_column($rows, 'gross')),
            ],
            'summary' => $summary,
            'summaryTotals' => $this->summaryTotals($summary),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function assertWithinPdfCap(array $filters): void
    {
        if ($this->rowCount($filters) > self::PDF_ROW_CAP) {
            throw new \RuntimeException(
                'PDF export exceeds '.self::PDF_ROW_CAP.' overhead rows; use CSV or Excel instead.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function pdfFilename(array $filters): string
    {
        return $this->isReport($filters) ? 'overhead-report.pdf' : 'overheads.pdf';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function streamPdf(array $filters, bool $inline = false): Response
    {
        $this->assertWithinPdfCap($filters);

        $pdf = Pdf::loadView('pdfs.overheads', $this->pdfData($filters))
            ->setOption('isPhpEnabled', true);

        $filename = $this->pdfFilename($filters);

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rowCount: int, gross: float}
     */
    protected function generatePdfFile(array $filters, string $absPath, ?callable $onChunk = null): array
    {
        $this->assertWithinPdfCap($filters);

        $data = $this->pdfData($filters);
        $binary = Pdf::loadView('pdfs.overheads', $data)->setOption('isPhpEnabled', true)->output();

        if ($onChunk !== null) {
            $onChunk();
        }

        file_put_contents($absPath, $binary);

        return ['rowCount' => count($data['rows']), 'gross' => $data['totals']['gross']];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rowCount: int, gross: float}
     */
    public function generateExportFile(string $format, array $filters, string $absPath, ?callable $onChunk = null): array
    {
        return match ($format) {
            'csv' => $this->writeCsvToPath($absPath, $filters, $onChunk),
            'xlsx' => $this->writeXlsxToPath($absPath, $filters, $onChunk),
            'pdf' => $this->generatePdfFile($filters, $absPath, $onChunk),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }
}
