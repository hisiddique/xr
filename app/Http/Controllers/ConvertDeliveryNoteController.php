<?php

namespace App\Http\Controllers;

use App\Actions\ConvertDeliveryNoteToInvoice;
use App\Models\Document;
use DomainException;
use Illuminate\Http\RedirectResponse;

class ConvertDeliveryNoteController extends Controller
{
    public function __invoke(Document $document, ConvertDeliveryNoteToInvoice $action): RedirectResponse
    {
        try {
            $invoice = $action->handle($document);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('invoices.edit', $invoice)
            ->with('success', "Delivery note {$document->doc_number} converted to invoice {$invoice->doc_number}.");
    }
}
