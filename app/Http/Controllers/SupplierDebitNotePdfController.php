<?php

namespace App\Http\Controllers;

use App\Models\SupplierDebitNote;
use App\Services\SupplierDebitNotePdfService;
use Symfony\Component\HttpFoundation\Response;

class SupplierDebitNotePdfController extends Controller
{
    public function __construct(private SupplierDebitNotePdfService $pdfService) {}

    /**
     * Stream the supplier debit note PDF inline.
     */
    public function show(SupplierDebitNote $debitNote): Response
    {
        return $this->pdfService->stream($debitNote);
    }

    /**
     * Download the supplier debit note PDF as an attachment.
     */
    public function download(SupplierDebitNote $debitNote): Response
    {
        return $this->pdfService->download($debitNote);
    }
}
