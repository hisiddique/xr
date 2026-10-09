<?php

use App\Models\Supplier;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public Supplier $supplier;

    public bool $hasNoRelatedRecords = false;

    public function checkRelatedRecords(): void
    {
        $this->hasNoRelatedRecords = $this->supplier->deletionBlockers() === [];
    }

    public function deleteSupplier(): void
    {
        if ($message = $this->supplier->deletionBlockedMessage($this->supplier->company_name)) {
            Flux::toast(heading: __('Cannot Delete Supplier'), text: $message, variant: 'danger', duration: 0);
            Flux::modal('delete-supplier-'.$this->supplier->id)->close();

            return;
        }

        $this->supplier->delete();

        Flux::toast(variant: 'success', text: __('Supplier deleted successfully.'));

        $this->dispatch('supplier-deleted');
        Flux::modal('delete-supplier-'.$this->supplier->id)->close();
    }
}; ?>

<div>
    <flux:button
        size="xs"
        variant="ghost"
        icon="trash"
        wire:click="checkRelatedRecords"
        x-on:click="$flux.modal('delete-supplier-{{ $supplier->id }}').show()"
        class="text-red-500 hover:text-red-700"
        :title="__('Delete')"
        data-row-action="delete"
    />

    <flux:modal name="delete-supplier-{{ $supplier->id }}" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Supplier') }}</flux:heading>
                <flux:subheading>
                    {{ __('Are you sure you want to delete :company? This action cannot be undone.', ['company' => $supplier->company_name]) }}
                </flux:subheading>
                @if($hasNoRelatedRecords)
                    <flux:text class="mt-2 text-emerald-600 dark:text-emerald-400">{{ $supplier->noRelatedRecordsMessage($supplier->company_name) }}</flux:text>
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="danger"
                    wire:click="deleteSupplier"
                    wire:loading.attr="disabled"
                >
                    {{ __('Delete Supplier') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
