<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\SupplierStatementService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SupplierStatementController extends Controller
{
    public function __construct(private SupplierStatementService $statementService) {}

    public function export(Request $request, Supplier $supplier): Response
    {
        $filters = $request->only(['dateFrom', 'dateTo', 'minBalance']);
        $filters['outstandingOnly'] = $request->boolean('outstandingOnly');
        $filters['includeInvoices'] = $request->boolean('includeInvoices', true);
        $filters['includeDebitNotes'] = $request->boolean('includeDebitNotes');
        $filters['includePayouts'] = $request->boolean('includePayouts');

        return $this->statementService->streamPdf($supplier, $filters, $request->boolean('inline'));
    }
}
