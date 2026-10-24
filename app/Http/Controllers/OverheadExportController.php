<?php

namespace App\Http\Controllers;

use App\ExportJobStatus;
use App\Jobs\ExportOverheadsJob;
use App\Models\ExportJob;
use App\Services\OverheadExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OverheadExportController extends Controller
{
    public function __construct(private OverheadExportService $exportService) {}

    public function export(Request $request, string $format, string $scope): Response|RedirectResponse
    {
        $filters = $request->only([
            'search', 'filterCategory', 'filterVat', 'dateFrom', 'dateTo', 'amountMin', 'amountMax',
        ]);
        $filters['scope'] = $scope;

        if ($format === 'pdf' && $this->exportService->rowCount($filters) > OverheadExportService::PDF_ROW_CAP) {
            return back()->with('error', 'PDF export exceeds '.OverheadExportService::PDF_ROW_CAP.' rows; use CSV or Excel instead.');
        }

        if ($format === 'pdf' && $request->boolean('inline')) {
            return $this->exportService->streamPdf($filters, true);
        }

        $exportJob = ExportJob::create([
            'status' => ExportJobStatus::Pending,
            'type' => $scope === 'report' ? 'overhead_report' : 'overheads',
            'format' => $format,
            'filters' => $filters,
            'rows_total' => $this->exportService->rowCount($filters),
            'created_by' => $request->user()->id,
        ]);

        ExportOverheadsJob::dispatch($exportJob->id);

        return redirect()->route('exports.index')->with('toast', 'Export queued — track its progress below.');
    }
}
