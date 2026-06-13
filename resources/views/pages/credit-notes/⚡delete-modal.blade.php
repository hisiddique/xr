<?php

use App\Models\Document;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public Document $document;

    public function deleteDocument(): void
    {
        $this->document->delete();

        Flux::toast(variant: 'success', text: __('Credit note deleted successfully.'));

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
        :title="__('Delete')"
        data-row-action="delete"
    />

    <flux:modal name="delete-document-{{ $document->id }}" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Credit Note') }}</flux:heading>
                <flux:subheading>
                    {{ __('Are you sure you want to delete :number? This will also delete all line items.', ['number' => $document->doc_number]) }}
                    <span class="mt-2 block text-xs text-zinc-500">
                        {{ __('Deleted credit notes can be restored from the Trash filter on the Credit Notes list.') }}
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
                    {{ __('Delete Credit Note') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
