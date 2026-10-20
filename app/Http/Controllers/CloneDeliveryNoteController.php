<?php

namespace App\Http\Controllers;

use App\Actions\CloneDeliveryNote;
use App\Models\Document;
use App\Models\Setting;
use DomainException;
use Illuminate\Http\RedirectResponse;

class CloneDeliveryNoteController extends Controller
{
    public function __invoke(Document $document, CloneDeliveryNote $action): RedirectResponse
    {
        if (! (bool) Setting::get('clone_delivery_notes_enabled', true)) {
            return redirect()->back()->with('error', 'Cloning delivery notes is disabled.');
        }

        try {
            $clone = $action->handle($document);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('delivery-notes.edit', $clone)
            ->with('success', "Delivery note {$clone->doc_number} cloned from {$document->doc_number}.")
            ->with('cloned_from', $document->doc_number);
    }
}
