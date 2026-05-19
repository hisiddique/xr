<?php

use App\Models\LookupTitle;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Titles')] class extends Component {
    public string $newTitle = '';

    public ?int $deletingTitleId = null;

    public function addTitle(): void
    {
        $this->validateOnly('newTitle', ['newTitle' => 'required|string|max:20|unique:lookup_titles,name']);
        LookupTitle::create(['name' => trim($this->newTitle)]);
        $this->newTitle = '';
        Flux::toast(variant: 'success', text: __('Title added.'));
    }

    public function deleteTitle(): void
    {
        if (! $this->deletingTitleId) {
            return;
        }

        LookupTitle::findOrFail($this->deletingTitleId)->delete();
        $this->deletingTitleId = null;
        Flux::modal('delete-title')->close();
        Flux::toast(variant: 'success', text: __('Title deleted.'));
    }

    #[Computed]
    public function titles()
    {
        return LookupTitle::orderBy('name')->get();
    }

    #[Computed]
    public function deletingTitle(): ?LookupTitle
    {
        return $this->deletingTitleId
            ? LookupTitle::find($this->deletingTitleId)
            : null;
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Titles"
        subtitle="Mr, Mrs, Dr, and other contact salutations."
    />

    <div class="max-w-xl">
        <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-200/70 px-6 py-4 dark:border-white/10">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Contact Titles</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Salutations used on customer records</p>
            </div>
            <div class="border-b border-zinc-100 px-6 py-4 dark:border-white/[0.06]">
                <form wire:submit="addTitle" class="flex gap-2">
                    <flux:input wire:model="newTitle" :placeholder="__('e.g. Dr')" maxlength="20" class="flex-1" />
                    <flux:button type="submit" variant="primary" icon="plus">Add</flux:button>
                </form>
                <flux:error name="newTitle" />
            </div>
            @if($this->titles->isEmpty())
                <x-ui.empty-state icon="tag" title="No titles yet" description="Add your first contact title above." />
            @else
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->titles as $title)
                            <tr>
                                <td class="px-6 py-3 text-sm text-zinc-900 dark:text-white">{{ $title->name }}</td>
                                <td class="px-6 py-3 text-right">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="$set('deletingTitleId', {{ $title->id }})"
                                        x-on:click="$flux.modal('delete-title').show()"
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
    <flux:modal name="delete-title" focusable class="max-w-sm" @close="$wire.set('deletingTitleId', null)">
        <div class="space-y-4">
            <flux:heading>
                @if($this->deletingTitle)
                    {{ __('Delete ":name"?', ['name' => $this->deletingTitle->name]) }}
                @endif
            </flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteTitle">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
