<?php

namespace App\Jobs;

use App\Exceptions\ExportCancelledException;
use App\ExportJobStatus;
use App\Models\ExportJob;
use App\Services\CustomerTurnoverReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportCustomerTurnoverReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(private readonly int $exportJobId)
    {
        $this->onQueue('exports');
    }

    public function handle(CustomerTurnoverReportService $service): void
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

            if ($this->isCancelled($export)) {
                throw new ExportCancelledException;
            }

            $onChunk = function () use ($export) {
                if ($this->isCancelled($export)) {
                    throw new ExportCancelledException;
                }

                $export->increment('rows_processed');
            };

            $result = $service->generateExportFile($export->format, $export->filters ?? [], $absPath, $onChunk);
            $customerCount = $result['customerCount'];

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

    private function isCancelled(ExportJob $export): bool
    {
        return ExportJob::whereKey($export->getKey())->whereNotNull('cancelled_at')->exists();
    }

    private function filename(ExportJob $export): string
    {
        return 'customer-turnover.'.$export->format;
    }
}
