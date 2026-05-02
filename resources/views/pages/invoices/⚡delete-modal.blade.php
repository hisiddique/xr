<?php

use App\DocumentStatus;
use App\Models\Document;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component {
    public Document $document;

    public function deleteDocument(): void
    {
        DB::transaction(function () {
            $source = $this->document->convertedFrom;

            $this->document->delete();

            if ($source && $source->status === DocumentStatus::Converted) {
                $source->update(['status' => DocumentStatus::Active]);
            }
        });

        Flux::toast(variant: 'success', text: __('Invoice deleted successfully.'));

        $this->dispatch('document-deleted');
        Flux::modal('delete-document-'.$this->document->id)->close();
    }
}; ?>

<div>
    <flux:button
        size="xs"
        variant="ghost"
        icon="trash"
        x-on:click="$flux.modal('delete-document-{{ $document->id }}').show()"
        class="text-red-500 hover:text-red-700"
    >
        {{ __('Delete') }}
    </flux:button>

    <flux:modal name="delete-document-{{ $document->id }}" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Invoice') }}</flux:heading>
                <flux:subheading>
                    {{ __('Are you sure you want to delete :number? This will also delete all line items.', ['number' => $document->doc_number]) }}
                    @if($document->convertedFrom)
                        <span class="mt-2 block text-xs text-violet-600 dark:text-violet-400">
                            {{ __('Source delivery note :dn will be reverted to Active so it can be re-converted.', ['dn' => $document->convertedFrom->doc_number]) }}
                        </span>
                    @endif
                    <span class="mt-2 block text-xs text-zinc-500">
                        {{ __('Deleted invoices can be restored from the Trash filter on the Invoices list.') }}
                    </span>
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="danger"
                    wire:click="deleteDocument"
                    wire:loading.attr="disabled"
                >
                    {{ __('Delete Invoice') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
