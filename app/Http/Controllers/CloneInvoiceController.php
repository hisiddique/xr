<?php

namespace App\Http\Controllers;

use App\Actions\CloneInvoice;
use App\Models\Document;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CloneInvoiceController extends Controller
{
    public function __invoke(Document $document, CloneInvoice $action): RedirectResponse
    {
        try {
            $clone = $action->handle($document);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('invoices.edit', $clone)
            ->with('success', "Invoice {$document->doc_number} cloned to {$clone->doc_number}.");
    }
}
