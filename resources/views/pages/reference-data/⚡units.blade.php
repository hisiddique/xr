<?php

use App\Models\LookupUnit;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Units')] class extends Component {
    public string $newUnit = '';

    public ?int $deletingUnitId = null;

    public function addUnit(): void
    {
        $this->validateOnly('newUnit', ['newUnit' => 'required|string|max:50|unique:lookup_units,name']);
        LookupUnit::create(['name' => trim($this->newUnit)]);
        $this->newUnit = '';
        Flux::toast(variant: 'success', text: __('Unit added.'));
    }

    public function deleteUnit(): void
    {
        if (! $this->deletingUnitId) {
            return;
        }

        LookupUnit::findOrFail($this->deletingUnitId)->delete();
        $this->deletingUnitId = null;
        Flux::modal('delete-unit')->close();
        Flux::toast(variant: 'success', text: __('Unit deleted.'));
    }

    #[Computed]
    public function units()
    {
        return LookupUnit::orderBy('name')->get();
    }

    #[Computed]
    public function deletingUnit(): ?LookupUnit
    {
        return $this->deletingUnitId
            ? LookupUnit::find($this->deletingUnitId)
            : null;
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Units"
        subtitle="each, box, kg, hour, etc."
    />

    <div class="max-w-xl">
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Units of Measure</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Units used on delivery note and invoice line items</p>
            </div>
            <div class="border-b border-zinc-100 px-6 py-4 dark:border-white/[0.06]">
                <form wire:submit="addUnit" class="flex gap-2">
                    <flux:input wire:model="newUnit" :placeholder="__('e.g. each')" maxlength="50" class="flex-1" data-add-input />
                    <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                </form>
                <flux:error name="newUnit" />
            </div>
            @if($this->units->isEmpty())
                <x-ui.empty-state icon="tag" title="No units yet" description="Add your first unit of measure above." />
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->units as $unit)
                            <tr>
                                <td class="px-6 py-3 text-sm text-zinc-900 dark:text-white">{{ $unit->name }}</td>
                                <td class="px-6 py-3 text-right">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="$set('deletingUnitId', {{ $unit->id }})"
                                        x-on:click="$flux.modal('delete-unit').show()"
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
    <flux:modal name="delete-unit" focusable class="max-w-sm" @close="$wire.set('deletingUnitId', null)">
        <div class="space-y-4">
            <flux:heading>
                @if($this->deletingUnit)
                    {{ __('Delete ":name"?', ['name' => $this->deletingUnit->name]) }}
                @endif
            </flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteUnit">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
