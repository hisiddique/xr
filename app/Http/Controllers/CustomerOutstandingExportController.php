<?php

namespace App\Http\Controllers;

use App\Services\CustomerOutstandingReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerOutstandingExportController extends Controller
{
    public function __construct(private CustomerOutstandingReportService $reportService) {}

    public function export(Request $request, string $format): Response
    {
        $filters = $request->only([
            'search', 'customerId', 'dateFrom', 'dateTo', 'amountMin', 'amountMax', 'osMin', 'osMax',
        ]);

        $customers = $this->reportService->customersForExport($filters);

        return match ($format) {
            'csv' => $this->reportService->streamCsv($customers),
            'xlsx' => $this->reportService->streamXlsx($customers),
            default => $this->reportService->streamPdf($customers),
        };
    }
}
