<?php

use App\Models\SupplierInvoice;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

new class extends Component {
    public SupplierInvoice $supplierInvoice;

    public function deleteSupplierInvoice(): void
    {
        foreach ($this->supplierInvoice->attachments ?? [] as $att) {
            Storage::disk('public')->delete($att['path']);
        }

        $this->supplierInvoice->delete();

        $this->dispatch('supplier-invoice-deleted');
        Flux::toast(variant: 'success', text: 'Supplier invoice deleted.');
        Flux::modal("delete-supplier-invoice-{$this->supplierInvoice->id}")->close();
    }
}; ?>

<div>
    <flux:modal name="delete-supplier-invoice-{{ $supplierInvoice->id }}" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Supplier Invoice') }}</flux:heading>
                <flux:subheading>
                    {{ __('Are you sure you want to delete invoice') }} <strong>{{ $supplierInvoice->supplier_invoice_no }}</strong>? {{ __('This cannot be undone.') }}
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="danger"
                    wire:click="deleteSupplierInvoice"
                    wire:loading.attr="disabled"
                >
                    {{ __('Delete Invoice') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
