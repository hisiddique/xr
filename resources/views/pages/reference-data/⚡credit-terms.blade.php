<?php

use App\Models\LookupCreditTerm;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Credit Terms')] class extends Component {
    public string $newCreditTerm = '';

    public ?int $deletingCreditTermId = null;

    public function addCreditTerm(): void
    {
        $this->validateOnly('newCreditTerm', ['newCreditTerm' => 'required|string|max:50|unique:lookup_credit_terms,name']);
        LookupCreditTerm::create(['name' => trim($this->newCreditTerm)]);
        $this->newCreditTerm = '';
        Flux::toast(variant: 'success', text: __('Credit term added.'));
    }

    public function deleteCreditTerm(): void
    {
        if (! $this->deletingCreditTermId) {
            return;
        }

        LookupCreditTerm::findOrFail($this->deletingCreditTermId)->delete();
        $this->deletingCreditTermId = null;
        Flux::modal('delete-term')->close();
        Flux::toast(variant: 'success', text: __('Credit term deleted.'));
    }

    #[Computed]
    public function creditTerms()
    {
        return LookupCreditTerm::orderBy('name')->get();
    }

    #[Computed]
    public function deletingCreditTerm(): ?LookupCreditTerm
    {
        return $this->deletingCreditTermId
            ? LookupCreditTerm::find($this->deletingCreditTermId)
            : null;
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Credit Terms"
        subtitle="Payment terms assigned to customers."
    />

    <div class="max-w-xl">
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Credit Terms</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Payment terms assigned to customers</p>
            </div>
            <div class="border-b border-zinc-100 px-6 py-4 dark:border-white/[0.06]">
                <form wire:submit="addCreditTerm" class="flex gap-2">
                    <flux:input wire:model="newCreditTerm" :placeholder="__('e.g. Net 30 days')" maxlength="50" class="flex-1" />
                    <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                </form>
                <flux:error name="newCreditTerm" />
            </div>
            @if($this->creditTerms->isEmpty())
                <x-ui.empty-state icon="tag" title="No credit terms yet" description="Add your first credit term above." />
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->creditTerms as $term)
                            <tr>
                                <td class="px-6 py-3 text-sm text-zinc-900 dark:text-white">{{ $term->name }}</td>
                                <td class="px-6 py-3 text-right">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="$set('deletingCreditTermId', {{ $term->id }})"
                                        x-on:click="$flux.modal('delete-term').show()"
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
    <flux:modal name="delete-term" focusable class="max-w-sm" @close="$wire.set('deletingCreditTermId', null)">
        <div class="space-y-4">
            <flux:heading>
                @if($this->deletingCreditTerm)
                    {{ __('Delete ":name"?', ['name' => $this->deletingCreditTerm->name]) }}
                @endif
            </flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteCreditTerm">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
