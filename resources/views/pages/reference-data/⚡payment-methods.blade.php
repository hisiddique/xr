<?php

use App\Models\LookupPaymentMethod;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payment Methods')] class extends Component {
    public string $newPaymentMethod = '';

    public ?int $deletingPaymentMethodId = null;

    public function addPaymentMethod(): void
    {
        $this->validateOnly('newPaymentMethod', ['newPaymentMethod' => 'required|string|max:50|unique:lookup_payment_methods,name']);
        LookupPaymentMethod::create(['name' => trim($this->newPaymentMethod)]);
        $this->newPaymentMethod = '';
        Flux::toast(variant: 'success', text: __('Payment method added.'));
    }

    public function deletePaymentMethod(): void
    {
        if (! $this->deletingPaymentMethodId) {
            return;
        }

        LookupPaymentMethod::findOrFail($this->deletingPaymentMethodId)->delete();
        $this->deletingPaymentMethodId = null;
        Flux::modal('delete-payment-method')->close();
        Flux::toast(variant: 'success', text: __('Payment method deleted.'));
    }

    #[Computed]
    public function paymentMethods()
    {
        return LookupPaymentMethod::orderBy('name')->get();
    }

    #[Computed]
    public function deletingPaymentMethod(): ?LookupPaymentMethod
    {
        return $this->deletingPaymentMethodId
            ? LookupPaymentMethod::find($this->deletingPaymentMethodId)
            : null;
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Payment Methods"
        subtitle="How payments are received (bank transfer, cheque, etc.)"
    />

    <div class="max-w-xl">
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Payment Methods</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">How payments are received (bank transfer, cheque, etc.)</p>
            </div>
            <div class="border-b border-zinc-100 px-6 py-4 dark:border-white/[0.06]">
                <form wire:submit="addPaymentMethod" class="flex gap-2">
                    <flux:input wire:model="newPaymentMethod" :placeholder="__('e.g. Bank Transfer')" maxlength="50" class="flex-1" data-add-input />
                    <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                </form>
                <flux:error name="newPaymentMethod" />
            </div>
            @if($this->paymentMethods->isEmpty())
                <x-ui.empty-state icon="credit-card" title="No payment methods yet" description="Add your first payment method above." />
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->paymentMethods as $paymentMethod)
                            <tr>
                                <td class="px-6 py-3 text-sm text-zinc-900 dark:text-white">{{ $paymentMethod->name }}</td>
                                <td class="px-6 py-3 text-right">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="$set('deletingPaymentMethodId', {{ $paymentMethod->id }})"
                                        x-on:click="$flux.modal('delete-payment-method').show()"
                                        class="text-rose-500 hover:text-rose-600"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Modals --}}
    <flux:modal name="delete-payment-method" focusable class="max-w-sm" @close="$wire.set('deletingPaymentMethodId', null)">
        <div class="space-y-4">
            <flux:heading>
                @if($this->deletingPaymentMethod)
                    {{ __('Delete ":name"?', ['name' => $this->deletingPaymentMethod->name]) }}
                @endif
            </flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deletePaymentMethod">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
