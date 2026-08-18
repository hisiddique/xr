<?php

namespace App\Jobs;

use App\Exceptions\ExportCancelledException;
use App\ExportJobStatus;
use App\Models\ExportJob;
use App\Services\CustomerOutstandingReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportCustomerOutstandingReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(private readonly int $exportJobId)
    {
        $this->onQueue('exports');
    }

    public function handle(CustomerOutstandingReportService $service): void
    {
        $export = ExportJob::findOrFail($this->exportJobId);

        if ($export->cancelled_at !== null) {
            $export->update(['status' => ExportJobStatus::Cancelled, 'finished_at' => now()]);

            return;
        }

        $export->update(['status' => ExportJobStatus::Running, 'started_at' => now()]);

        $path = 'exports/'.$export->id.'/'.$this->filename($export);

        try {
            Storage::disk('local')->makeDirectory('exports/'.$export->id);
            $absPath = Storage::disk('local')->path($path);

            $onChunk = function () use ($export) {
                if ($this->isCancelled($export)) {
                    throw new ExportCancelledException;
                }

                $export->increment('rows_processed');
            };

            [$customerCount, $totalOutstanding] = match ($export->format) {
                'csv' => $this->writeCsv($service, $export, $absPath, $onChunk),
                'xlsx' => $this->writeXlsx($service, $export, $absPath, $onChunk),
                'pdf' => $this->writePdf($service, $export, $absPath),
            };

            $export->update([
                'status' => ExportJobStatus::Completed,
                'download_path' => $path,
                'rows_processed' => $customerCount,
                'finished_at' => now(),
            ]);
        } catch (ExportCancelledException) {
            Storage::disk('local')->delete($path);
            $export->update(['status' => ExportJobStatus::Cancelled, 'finished_at' => now()]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            $export->update([
                'status' => ExportJobStatus::Failed,
                'error' => Str::limit($e->getMessage(), 2000),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function writeCsv(CustomerOutstandingReportService $service, ExportJob $export, string $absPath, callable $onChunk): array
    {
        $result = $service->writeCsvToPath($absPath, $service->exportChunks($export->filters ?? []), $onChunk);

        return [$result['customerCount'], $result['totalOutstanding']];
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function writeXlsx(CustomerOutstandingReportService $service, ExportJob $export, string $absPath, callable $onChunk): array
    {
        $result = $service->writeXlsxToPath($absPath, $service->exportChunks($export->filters ?? []), $onChunk);

        return [$result['customerCount'], $result['totalOutstanding']];
    }

    /**
     * dompdf can't be interrupted mid-render, so cancellation for PDF is only
     * checked before starting the render and before persisting the finished
     * output — a cancel requested mid-render still completes the render.
     *
     * @return array{0: int, 1: float}
     */
    private function writePdf(CustomerOutstandingReportService $service, ExportJob $export, string $absPath): array
    {
        if ($this->isCancelled($export)) {
            throw new ExportCancelledException;
        }

        $data = $service->buildExportData($export->filters ?? []);

        if ($service->invoiceRowCount($data) > CustomerOutstandingReportService::PDF_ROW_CAP) {
            throw new \RuntimeException(
                'PDF export exceeds '.CustomerOutstandingReportService::PDF_ROW_CAP.' invoice rows; use CSV or Excel instead.'
            );
        }

        $binary = $service->pdfBinary($data);

        if ($this->isCancelled($export)) {
            throw new ExportCancelledException;
        }

        file_put_contents($absPath, $binary);

        return [count($data), $service->exportTotalOutstanding($data)];
    }

    private function isCancelled(ExportJob $export): bool
    {
        return ExportJob::whereKey($export->getKey())->whereNotNull('cancelled_at')->exists();
    }

    private function filename(ExportJob $export): string
    {
        return 'customer-outstanding-payments.'.$export->format;
    }
}
