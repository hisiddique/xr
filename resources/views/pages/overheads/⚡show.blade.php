<?php

use App\Models\Overhead;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Overhead')] class extends Component {
    public Overhead $overhead;

    public function delete(): void
    {
        $this->overhead->delete();
        Flux::toast(variant: 'success', text: 'Overhead deleted.');
        $this->redirect(route('overheads.index'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">

    <x-ui.page-header
        :title="'Overhead #'.$overhead->id"
        :subtitle="$overhead->expense_date->format('d M Y')"
    >
        <x-slot:action>
            <div class="flex items-center gap-2">
                <flux:button variant="ghost" icon="arrow-left" size="sm" :href="route('overheads.index')" wire:navigate>
                    Back
                </flux:button>
                <flux:button variant="primary" icon="pencil" size="sm" :href="route('overheads.edit', $overhead)" wire:navigate>
                    Edit
                </flux:button>
                <flux:button variant="danger" icon="trash" size="sm" x-on:click="$flux.modal('delete-overhead').show()">
                    Delete
                </flux:button>
            </div>
        </x-slot:action>
    </x-ui.page-header>

    <div class="rounded-2xl border border-zinc-200/70 bg-white shadow dark:border-white/10 dark:bg-zinc-900">
        <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Details</h2>
        </div>

        <dl class="grid gap-6 p-6 md:grid-cols-2">
            <div>
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Category</dt>
                <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $overhead->category->name }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Date</dt>
                <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $overhead->expense_date->format('d M Y') }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Amount</dt>
                <dd class="mt-1 font-mono text-sm font-semibold text-zinc-900 dark:text-white">£{{ number_format($overhead->amount, 2) }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">VAT</dt>
                <dd class="mt-1">
                    @if($overhead->has_vat)
                        <flux:badge color="emerald" size="sm">Includes VAT</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">Excludes VAT</flux:badge>
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Payment Method</dt>
                <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $overhead->payment_method }}</dd>
            </div>
        </dl>
    </div>

    <flux:modal name="delete-overhead" focusable class="max-w-sm">
        <div class="space-y-4">
            <flux:heading>Delete overhead #{{ $overhead->id }}?</flux:heading>
            <p class="text-sm text-zinc-500">This action cannot be undone.</p>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
