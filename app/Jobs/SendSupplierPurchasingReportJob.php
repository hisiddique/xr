<?php

namespace App\Jobs;

use App\ExportJobStatus;
use App\Mail\SupplierPurchasingReportMail;
use App\Models\ExportJob;
use App\Services\SupplierPurchasingReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SendSupplierPurchasingReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * Above this combined size, files are too likely to bounce as email
     * attachments (most providers reject well under their stated limit once
     * base64 encoding's ~37% overhead is added), so we send download links
     * instead of attaching.
     */
    private const ATTACHMENT_SIZE_CAP_BYTES = 15 * 1024 * 1024;

    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'csv' => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * @param  array<int, string>  $emails
     * @param  array<int, string>  $formats
     */
    public function __construct(
        private readonly int $exportJobId,
        private readonly array $emails,
        private readonly array $formats,
        private readonly ?string $notes = null,
    ) {
        $this->onQueue('exports');
    }

    public function handle(SupplierPurchasingReportService $service): void
    {
        $export = ExportJob::findOrFail($this->exportJobId);

        if ($export->cancelled_at !== null) {
            $export->update(['status' => ExportJobStatus::Cancelled, 'finished_at' => now()]);

            return;
        }

        $export->update(['status' => ExportJobStatus::Running, 'started_at' => now()]);

        try {
            $files = $this->generateFiles($service, $export);

            $totalBytes = array_sum(array_column($files, 'size'));
            $withinCap = $totalBytes <= self::ATTACHMENT_SIZE_CAP_BYTES;

            $attachments = [];
            $downloadLinks = [];

            foreach ($files as $file) {
                if ($withinCap) {
                    $attachments[] = [
                        'data' => Storage::disk('local')->get($file['path']),
                        'filename' => 'supplier-purchasing-report.'.$file['format'],
                        'mime' => self::MIME_TYPES[$file['format']],
                    ];
                } else {
                    $downloadLinks[] = [
                        'format' => strtoupper($file['format']),
                        'url' => route('exports.download', $file['exportJob']),
                    ];
                }
            }

            $invoiceCount = $files[0]['invoiceCount'] ?? 0;
            $totalNet = $files[0]['totalNet'] ?? 0.0;
            $totalVat = $files[0]['totalVat'] ?? 0.0;
            $totalGross = $files[0]['totalGross'] ?? 0.0;

            Mail::to($this->emails)->send(new SupplierPurchasingReportMail(
                $invoiceCount,
                $totalNet,
                $totalVat,
                $totalGross,
                $this->notes,
                $attachments,
                $downloadLinks,
            ));

            $export->update(['status' => ExportJobStatus::Completed, 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $export->update([
                'status' => ExportJobStatus::Failed,
                'error' => Str::limit($e->getMessage(), 2000),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Generates one file per requested format the same way a standalone
     * download does (memory-bounded CSV/XLSX writers, capped PDF), tracked
     * as its own ExportJob row so it's downloadable and visible on the
     * Exports page like any other export.
     *
     * @return array<int, array{format: string, path: string, size: int, exportJob: ExportJob, invoiceCount: int, totalNet: float, totalVat: float, totalGross: float}>
     */
    private function generateFiles(SupplierPurchasingReportService $service, ExportJob $export): array
    {
        $files = [];

        foreach ($this->formats as $format) {
            $fileExport = ExportJob::create([
                'status' => ExportJobStatus::Running,
                'type' => $export->type,
                'format' => $format,
                'filters' => $export->filters,
                'rows_total' => $service->suppliersQuery($export->filters ?? [])->count(),
                'started_at' => now(),
                'created_by' => $export->created_by,
            ]);

            $path = 'exports/'.$fileExport->id.'/supplier-purchasing-report.'.$format;

            try {
                Storage::disk('local')->makeDirectory('exports/'.$fileExport->id);
                $absPath = Storage::disk('local')->path($path);

                $result = $service->generateExportFile($format, $export->filters ?? [], $absPath);

                $fileExport->update([
                    'status' => ExportJobStatus::Completed,
                    'download_path' => $path,
                    'rows_processed' => $result['invoiceCount'],
                    'finished_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                $fileExport->update([
                    'status' => ExportJobStatus::Failed,
                    'error' => Str::limit($e->getMessage(), 2000),
                    'finished_at' => now(),
                ]);

                throw $e;
            }

            $files[] = [
                'format' => $format,
                'path' => $path,
                'size' => Storage::disk('local')->size($path),
                'exportJob' => $fileExport,
                'invoiceCount' => $result['invoiceCount'],
                'totalNet' => $result['totalNet'],
                'totalVat' => $result['totalVat'],
                'totalGross' => $result['totalGross'],
            ];
        }

        return $files;
    }
}
