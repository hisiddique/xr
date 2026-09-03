<?php

namespace App\Http\Controllers;

use App\ExportJobStatus;
use App\Jobs\ExportCustomerTurnoverReportJob;
use App\Models\ExportJob;
use App\Services\CustomerTurnoverReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerTurnoverExportController extends Controller
{
    public function __construct(private CustomerTurnoverReportService $reportService) {}

    public function export(Request $request, string $format): Response|RedirectResponse
    {
        $filters = $request->only([
            'search', 'customerId', 'dateFrom', 'dateTo', 'totalMin', 'totalMax', 'sortColumn', 'sortDirection',
        ]);
        $filters['includeOutstanding'] = $request->boolean('includeOutstanding');

        // The inline PDF preview is a synchronous "Print" action users expect
        // to render instantly, so it stays outside the job queue — still
        // guarded by the same row cap as the queued PDF download.
        if ($format === 'pdf' && $request->boolean('inline')) {
            $data = $this->reportService->buildExportData($filters);

            if ($this->reportService->rowCount($data) > CustomerTurnoverReportService::PDF_ROW_CAP) {
                return back()->with('error', 'PDF export exceeds '.CustomerTurnoverReportService::PDF_ROW_CAP.' customer rows; use CSV or Excel instead.');
            }

            return $this->reportService->streamPdf($data, $filters, true);
        }

        if ($format === 'pdf') {
            $data = $this->reportService->buildExportData($filters);

            if ($this->reportService->rowCount($data) > CustomerTurnoverReportService::PDF_ROW_CAP) {
                return back()->with('error', 'PDF export exceeds '.CustomerTurnoverReportService::PDF_ROW_CAP.' customer rows; use CSV or Excel instead.');
            }
        }

        $exportJob = ExportJob::create([
            'status' => ExportJobStatus::Pending,
            'type' => 'customer_turnover',
            'format' => $format,
            'filters' => $filters,
            'rows_total' => $this->reportService->customersQuery($filters)->count(),
            'created_by' => $request->user()->id,
        ]);

        ExportCustomerTurnoverReportJob::dispatch($exportJob->id);

        return redirect()->route('exports.index')->with('toast', 'Export queued — track its progress below.');
    }
}
