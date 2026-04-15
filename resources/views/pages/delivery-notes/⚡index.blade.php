<?php

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Document;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Delivery Notes')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function deliveryNotes()
    {
        return Document::deliveryNotes()
            ->with('customer')
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('doc_number', 'like', "%{$this->search}%")
                    ->orWhereHas('customer', fn ($q) => $q->where('company_name', 'like', "%{$this->search}%"));
            }))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(15);
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Delivery Notes"
        subtitle="Track and manage all delivery documents."
    >
        <x-slot:action>
            <flux:button variant="primary" icon="plus" :href="route('delivery-notes.create')" wire:navigate>
                New Delivery Note
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search by doc number or customer…')"
                clearable
                class="flex-1 max-w-sm"
            />

            {{-- Status filter pills --}}
            <div class="flex flex-wrap gap-1.5">
                @foreach(['' => 'All', 'active' => 'Active', 'converted' => 'Converted', 'emailed' => 'Emailed'] as $val => $label)
                    <button
                        type="button"
                        wire:click="$set('status', '{{ $val }}')"
                        @class([
                            'rounded-full px-3 py-1 text-xs font-medium transition-colors',
                            'bg-indigo-600 text-white shadow-sm' => $status === $val,
                            'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/15' => $status !== $val,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Table card --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">

        @if($this->deliveryNotes->isEmpty())
            <x-ui.empty-state
                icon="truck"
                title="No delivery notes found"
                :description="($search || $status) ? 'Try adjusting your search or filters.' : 'Create your first delivery note to get started.'"
            >
                @unless($search || $status)
                    <x-slot:action>
                        <flux:button variant="primary" :href="route('delivery-notes.create')" wire:navigate>
                            New Delivery Note
                        </flux:button>
                    </x-slot:action>
                @endunless
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">#</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->deliveryNotes as $note)
                            <tr class="transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5">
                                <td class="px-6 py-4">
                                    <a href="{{ route('delivery-notes.show', $note) }}" wire:navigate class="font-mono text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                        {{ $note->doc_number }}
                                    </a>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2.5">
                                        <x-ui.avatar :name="$note->customer->company_name" size="xs" />
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $note->customer->company_name }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-zinc-500 dark:text-zinc-400">{{ $note->doc_date->format('d M Y') }}</td>
                                <td class="px-6 py-4">
                                    @php
                                        $statusColor = match($note->status->value) {
                                            'active'    => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
                                            'converted' => 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-400',
                                            'emailed'   => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400',
                                            default     => 'bg-zinc-50 text-zinc-700 ring-zinc-600/20 dark:bg-zinc-500/10 dark:text-zinc-400',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusColor }}">
                                        {{ $note->status->label() }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('delivery-notes.show', $note)" wire:navigate />
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('delivery-notes.edit', $note)" wire:navigate />
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="envelope"
                                            x-on:click="$flux.modal('email-document-{{ $note->id }}').show()"
                                        />
                                        @if($note->status === DocumentStatus::Active)
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-path"
                                                class="text-violet-600 dark:text-violet-400"
                                                x-on:click="$flux.modal('convert-dn-index-{{ $note->id }}').show()"
                                            />
                                            <flux:modal name="convert-dn-index-{{ $note->id }}" focusable class="max-w-md">
                                                <div class="space-y-6">
                                                    <div>
                                                        <flux:heading size="lg">{{ __('Convert to Invoice') }}</flux:heading>
                                                        <flux:subheading>
                                                            {{ __('This will create a new invoice from :number and mark this delivery note as converted.', ['number' => $note->doc_number]) }}
                                                        </flux:subheading>
                                                    </div>
                                                    <div class="flex justify-end gap-3">
                                                        <flux:modal.close>
                                                            <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                                                        </flux:modal.close>
                                                        <form method="POST" action="{{ route('delivery-notes.convert', $note) }}">
                                                            @csrf
                                                            <flux:button variant="primary" type="submit" icon="arrow-path">
                                                                {{ __('Convert') }}
                                                            </flux:button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </flux:modal>
                                        @endif
                                        <livewire:pages::delivery-notes.delete-modal :document="$note" :key="'delete-'.$note->id" />
                                        <livewire:pages::documents.email-modal :document="$note" :key="'email-'.$note->id" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-100 px-6 py-4 dark:border-white/[0.06]">
                {{ $this->deliveryNotes->links() }}
            </div>
        @endif
    </div>

</div>
