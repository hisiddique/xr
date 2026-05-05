<?php

use App\Models\Customer;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public Customer $customer;

    public function deleteCustomer(): void
    {
        $this->customer->delete();

        Flux::toast(variant: 'success', text: __('Customer deleted successfully.'));

        $this->dispatch('customer-deleted');
        Flux::modal('delete-customer-'.$this->customer->id)->close();
    }
}; ?>

<div>
    <flux:button
        size="xs"
        variant="ghost"
        icon="trash"
        x-on:click="$flux.modal('delete-customer-{{ $customer->id }}').show()"
        class="text-red-500 hover:text-red-700"
        :title="__('Delete')"
        data-row-action="delete"
    />

    <flux:modal name="delete-customer-{{ $customer->id }}" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Customer') }}</flux:heading>
                <flux:subheading>
                    {{ __('Are you sure you want to delete :company? This action cannot be undone.', ['company' => $customer->company_name]) }}
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="danger"
                    wire:click="deleteCustomer"
                    wire:loading.attr="disabled"
                >
                    {{ __('Delete Customer') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
