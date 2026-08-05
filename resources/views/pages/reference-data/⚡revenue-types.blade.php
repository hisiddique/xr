<?php

use App\Models\LookupRevenueType;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Revenue Types')] class extends Component {
    public string $newType = '';

    public ?int $deletingTypeId = null;

    public function addType(): void
    {
        $this->newType = trim($this->newType);
        $this->validateOnly('newType', ['newType' => 'required|string|max:50|unique:lookup_revenue_types,name']);
        LookupRevenueType::create(['name' => $this->newType]);
        $this->newType = '';
        Flux::toast(variant: 'success', text: __('Revenue type added.'));
    }

    public function deleteType(): void
    {
        if (! $this->deletingTypeId) {
            return;
        }

        LookupRevenueType::findOrFail($this->deletingTypeId)->delete();
        $this->deletingTypeId = null;
        Flux::modal('delete-revenue-type')->close();
        Flux::toast(variant: 'success', text: __('Revenue type deleted.'));
    }

    #[Computed]
    public function types()
    {
        return LookupRevenueType::orderBy('name')->get();
    }

    #[Computed]
    public function deletingType(): ?LookupRevenueType
    {
        return $this->deletingTypeId
            ? LookupRevenueType::find($this->deletingTypeId)
            : null;
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Revenue Types"
        subtitle="Product Sales, Service Revenue, etc."
    />

    <div class="max-w-xl">
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Revenue Types</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Classification used to group customer revenue</p>
            </div>
            <div class="border-b border-zinc-100 px-6 py-4 dark:border-white/[0.06]">
                <form wire:submit="addType" class="flex gap-2">
                    <flux:input wire:model="newType" :placeholder="__('e.g. Product Sales')" maxlength="50" class="flex-1" data-add-input />
                    <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                </form>
                <flux:error name="newType" />
            </div>
            @if($this->types->isEmpty())
                <x-ui.empty-state icon="tag" title="No revenue types yet" description="Add your first revenue type above." />
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->types as $type)
                            <tr>
                                <td class="px-6 py-3 text-sm text-zinc-900 dark:text-white">{{ $type->name }}</td>
                                <td class="px-6 py-3 text-right">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="$set('deletingTypeId', {{ $type->id }})"
                                        x-on:click="$flux.modal('delete-revenue-type').show()"
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
    <flux:modal name="delete-revenue-type" focusable class="max-w-sm" @close="$wire.set('deletingTypeId', null)">
        <div class="space-y-4">
            <flux:heading>
                @if($this->deletingType)
                    {{ __('Delete ":name"?', ['name' => $this->deletingType->name]) }}
                @endif
            </flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteType">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
