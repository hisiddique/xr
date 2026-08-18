<?php

namespace App\Http\Controllers;

use App\ExportJobStatus;
use App\Models\ExportJob;
use Illuminate\Support\Facades\Storage;

class ExportDownloadController extends Controller
{
    public function download(ExportJob $exportJob): mixed
    {
        abort_unless($exportJob->created_by === auth()->id(), 403);
        abort_unless($exportJob->status === ExportJobStatus::Completed && $exportJob->download_path, 404);

        return Storage::disk('local')->download(
            $exportJob->download_path,
            'customer-outstanding-payments.'.$exportJob->format,
        );
    }
}
