<?php

namespace App\Jobs;

use App\ExportJobStatus;
use App\Models\ExportJob;
use App\Services\CustomerOutstandingReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SendCustomerOutstandingReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

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

    public function handle(CustomerOutstandingReportService $service): void
    {
        $export = ExportJob::findOrFail($this->exportJobId);

        if ($export->cancelled_at !== null) {
            $export->update(['status' => ExportJobStatus::Cancelled, 'finished_at' => now()]);

            return;
        }

        $export->update(['status' => ExportJobStatus::Running, 'started_at' => now()]);

        try {
            $service->sendReport($export->filters ?? [], $this->emails, $this->formats, $this->notes);

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
}
