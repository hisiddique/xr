<?php

use App\Models\LookupCreditLimit;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Credit Limits')] class extends Component {
    public string $newCreditLimit = '';

    public ?int $deletingCreditLimitId = null;

    public function addCreditLimit(): void
    {
        $this->validateOnly('newCreditLimit', ['newCreditLimit' => 'required|numeric|min:0|unique:lookup_credit_limits,amount']);
        LookupCreditLimit::create(['amount' => (float) $this->newCreditLimit]);
        $this->newCreditLimit = '';
        Flux::toast(variant: 'success', text: __('Credit limit added.'));
    }

    public function deleteCreditLimit(int $id): void
    {
        LookupCreditLimit::findOrFail($id)->delete();
        $this->deletingCreditLimitId = null;
        Flux::toast(variant: 'success', text: __('Credit limit deleted.'));
    }

    #[Computed]
    public function creditLimits()
    {
        return LookupCreditLimit::orderBy('amount')->get();
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Credit Limits"
        subtitle="Available credit limit amounts assigned to customers."
    />

    <div class="max-w-xl">
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Credit Limits</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Available credit limit amounts</p>
            </div>
            <div class="border-b border-zinc-100 px-6 py-4 dark:border-white/[0.06]">
                <form wire:submit="addCreditLimit" class="flex gap-2">
                    <flux:input wire:model="newCreditLimit" type="number" min="0" step="0.01" :placeholder="__('e.g. 5000')" class="flex-1" />
                    <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                </form>
                <flux:error name="newCreditLimit" />
            </div>
            @if($this->creditLimits->isEmpty())
                <x-ui.empty-state icon="tag" title="No credit limits yet" description="Add your first credit limit above." />
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->creditLimits as $limit)
                            <tr>
                                <td class="px-6 py-3 font-mono text-sm text-zinc-900 dark:text-white">£{{ number_format($limit->amount, 2) }}</td>
                                <td class="px-6 py-3 text-right">
                                    <flux:button size="xs" variant="ghost" icon="trash" x-on:click="$flux.modal('delete-limit-{{ $limit->id }}').show()" class="text-rose-500 hover:text-rose-600" />
                                    <flux:modal name="delete-limit-{{ $limit->id }}" focusable class="max-w-sm">
                                        <div class="space-y-4">
                                            <flux:heading>{{ __('Delete £:amount?', ['amount' => number_format($limit->amount, 2)]) }}</flux:heading>
                                            <div class="flex justify-end gap-2">
                                                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                                                <flux:button variant="danger" wire:click="deleteCreditLimit({{ $limit->id }})">Delete</flux:button>
                                            </div>
                                        </div>
                                    </flux:modal>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

</div>
