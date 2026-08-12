<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerStatementService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerStatementController extends Controller
{
    public function __construct(private CustomerStatementService $statementService) {}

    public function export(Request $request, Customer $customer): Response
    {
        $filters = $request->only(['dateFrom', 'dateTo', 'minBalance']);
        $filters['outstandingOnly'] = $request->boolean('outstandingOnly');

        return $this->statementService->streamPdf($customer, $filters, $request->boolean('inline'));
    }
}
