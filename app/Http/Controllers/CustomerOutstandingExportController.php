<?php

namespace App\Http\Controllers;

use App\ExportJobStatus;
use App\Jobs\ExportCustomerOutstandingReportJob;
use App\Models\ExportJob;
use App\Services\CustomerOutstandingReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerOutstandingExportController extends Controller
{
    public function __construct(private CustomerOutstandingReportService $reportService) {}

    public function export(Request $request, string $format): Response|RedirectResponse
    {
        $filters = $request->only([
            'search', 'customerId', 'dateFrom', 'dateTo', 'amountMin', 'amountMax', 'osMin', 'osMax',
        ]);
        $filters['showPaid'] = $request->boolean('showPaid');

        // The inline PDF preview is a synchronous "Print" action users expect
        // to render instantly, so it stays outside the job queue — still
        // guarded by the same row cap as the queued PDF download.
        if ($format === 'pdf' && $request->boolean('inline')) {
            $data = $this->reportService->buildExportData($filters);

            if ($this->reportService->invoiceRowCount($data) > CustomerOutstandingReportService::PDF_ROW_CAP) {
                return back()->with('error', 'PDF export exceeds '.CustomerOutstandingReportService::PDF_ROW_CAP.' invoice rows; use CSV or Excel instead.');
            }

            return $this->reportService->streamPdf($data, true);
        }

        if ($format === 'pdf') {
            $data = $this->reportService->buildExportData($filters);

            if ($this->reportService->invoiceRowCount($data) > CustomerOutstandingReportService::PDF_ROW_CAP) {
                return back()->with('error', 'PDF export exceeds '.CustomerOutstandingReportService::PDF_ROW_CAP.' invoice rows; use CSV or Excel instead.');
            }
        }

        $exportJob = ExportJob::create([
            'status' => ExportJobStatus::Pending,
            'type' => 'customer_outstanding_payments',
            'format' => $format,
            'filters' => $filters,
            'rows_total' => $this->reportService->customersQuery($filters)->count(),
            'created_by' => $request->user()->id,
        ]);

        ExportCustomerOutstandingReportJob::dispatch($exportJob->id);

        return redirect()->route('exports.index')->with('toast', 'Export queued — track its progress below.');
    }
}
