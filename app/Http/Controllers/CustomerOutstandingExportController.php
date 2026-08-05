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

        $data = $this->reportService->buildExportData($filters);

        return match ($format) {
            'csv' => $this->reportService->streamCsv($data),
            'xlsx' => $this->reportService->streamXlsx($data),
            default => $this->reportService->streamPdf($data),
        };
    }
}
