<?php

namespace App\Services;

use App\Models\SupplierDebitNote;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class SupplierDebitNotePdfService
{
    /**
     * Generate raw PDF binary for a supplier debit note.
     */
    public function generate(SupplierDebitNote $debitNote): string
    {
        $debitNote->loadMissing(['supplier', 'supplierInvoice', 'items']);

        return Pdf::loadView('pdfs.supplier-debit-note', ['debitNote' => $debitNote])
            ->setOption('isPhpEnabled', true)->output();
    }

    /**
     * Return a streamed inline PDF response.
     */
    public function stream(SupplierDebitNote $debitNote): Response
    {
        $debitNote->loadMissing(['supplier', 'supplierInvoice', 'items']);

        return Pdf::loadView('pdfs.supplier-debit-note', ['debitNote' => $debitNote])
            ->setOption('isPhpEnabled', true)
            ->stream("{$debitNote->reference}.pdf");
    }

    /**
     * Return a downloadable PDF response.
     */
    public function download(SupplierDebitNote $debitNote): Response
    {
        $debitNote->loadMissing(['supplier', 'supplierInvoice', 'items']);

        return Pdf::loadView('pdfs.supplier-debit-note', ['debitNote' => $debitNote])
            ->setOption('isPhpEnabled', true)
            ->download("{$debitNote->reference}.pdf");
    }
}
