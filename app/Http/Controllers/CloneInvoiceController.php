<?php

namespace App\Http\Controllers;

use App\Actions\CloneInvoice;
use App\Models\Document;
use App\Models\Setting;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CloneInvoiceController extends Controller
{
    public function __invoke(Document $document, CloneInvoice $action): RedirectResponse
    {
        if (! (bool) Setting::get('clone_invoices_enabled', true)) {
            return redirect()->back()->with('error', 'Cloning invoices is disabled.');
        }

        try {
            $clone = $action->handle($document);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('invoices.edit', $clone)
            ->with('success', "Invoice {$clone->doc_number} cloned from {$document->doc_number}.")
            ->with('cloned_from', $document->doc_number);
    }
}
