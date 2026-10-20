<?php

namespace App\Http\Controllers;

use App\Actions\CloneDeliveryNote;
use App\Models\Document;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CloneDeliveryNoteController extends Controller
{
    public function __invoke(Document $document, CloneDeliveryNote $action): RedirectResponse
    {
        try {
            $clone = $action->handle($document);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('delivery-notes.edit', $clone)
            ->with('success', "Delivery note {$document->doc_number} cloned to {$clone->doc_number}.");
    }
}
