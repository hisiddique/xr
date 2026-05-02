<?php

use App\Actions\ConvertDeliveryNoteToInvoice;
use App\DocumentStatus;
use App\DocumentType;
use App\Models\Document;
use Flux\Flux;
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

    /** @var array<int, int> */
    public array $selectedIds = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    public function bulkConvert(ConvertDeliveryNoteToInvoice $action): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        $notes = Document::deliveryNotes()
            ->whereIn('id', $this->selectedIds)
            ->get();

        $converted = 0;
        $skipped = 0;

        foreach ($notes as $note) {
            try {
                $action->handle($note);
                $converted++;
            } catch (\DomainException) {
                $skipped++;
            }
        }

        $this->selectedIds = [];

        Flux::modal('bulk-convert-dns')->close();

        $message = match (true) {
            $converted > 0 && $skipped > 0 => __(':n converted, :s skipped (already converted or ineligible).', ['n' => $converted, 's' => $skipped]),
            $converted > 0 => __(':n delivery note(s) converted to invoices.', ['n' => $converted]),
            default => __('Nothing converted — all selected delivery notes were ineligible.'),
        };

        Flux::toast(variant: $converted > 0 ? 'success' : 'warning', text: $message);
    }

    #[Computed]
    public function deliveryNotes()
    {
        return Document::deliveryNotes()
            ->with('customer')
            ->withExists(['emailLogs as has_been_emailed' => fn ($q) => $q->where('status', 'sent')])
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('doc_number', 'like', "%{$this->search}%")
                    ->orWhereHas('customer', fn ($q) => $q->where('company_name', 'like', "%{$this->search}%"));
            }))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(15);
    }

    /** @return array<int, int> */
    #[Computed]
    public function selectableIdsOnPage(): array
    {
        return $this->deliveryNotes
            ->where('status', DocumentStatus::Active)
            ->pluck('id')
            ->all();
    }

    #[Computed]
    public function pageFullySelected(): bool
    {
        $ids = $this->selectableIdsOnPage;

        return ! empty($ids) && empty(array_diff($ids, $this->selectedIds));
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

    {{-- Bulk action bar --}}
    @if(count($selectedIds) > 0)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-indigo-500/20 dark:bg-indigo-500/10">
            <div class="flex items-center gap-2 text-sm">
                <flux:icon.check-circle class="size-4 text-indigo-600 dark:text-indigo-400" />
                <span class="font-medium text-indigo-900 dark:text-indigo-200">
                    {{ trans_choice(':count delivery note selected|:count delivery notes selected', count($selectedIds), ['count' => count($selectedIds)]) }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="ghost" wire:click="clearSelection">
                    {{ __('Clear') }}
                </flux:button>
                <flux:button
                    size="sm"
                    variant="primary"
                    icon="arrow-path"
                    x-on:click="$flux.modal('bulk-convert-dns').show()"
                >
                    {{ __('Convert to Invoices') }}
                </flux:button>
            </div>
        </div>

        <flux:modal name="bulk-convert-dns" focusable class="max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ trans_choice('Convert :count delivery note to an invoice?|Convert :count delivery notes to invoices?', count($selectedIds), ['count' => count($selectedIds)]) }}
                    </flux:heading>
                    <flux:subheading>
                        {{ __('Each selected delivery note will become its own invoice. Already-converted or ineligible delivery notes will be skipped.') }}
                    </flux:subheading>
                </div>
                <div class="flex justify-end gap-3">
                    <flux:modal.close>
                        <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button
                        variant="primary"
                        icon="arrow-path"
                        wire:click="bulkConvert"
                        wire:loading.attr="disabled"
                        wire:target="bulkConvert"
                    >
                        <span wire:loading.remove wire:target="bulkConvert">{{ __('Convert') }}</span>
                        <span wire:loading wire:target="bulkConvert">{{ __('Converting…') }}</span>
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

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
            <div class="overflow-x-auto" x-data="tableNav">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="w-10 px-4 py-3">
                                @if(count($this->selectableIdsOnPage) > 0)
                                    <input
                                        type="checkbox"
                                        @checked($this->pageFullySelected)
                                        x-on:change="$wire.set('selectedIds', $event.target.checked ? Array.from(new Set([...$wire.selectedIds, ...{{ json_encode($this->selectableIdsOnPage) }}])) : $wire.selectedIds.filter(id => !{{ json_encode($this->selectableIdsOnPage) }}.includes(id)))"
                                        class="size-4 cursor-pointer rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-600 dark:bg-zinc-800"
                                        title="{{ __('Select all active on this page') }}"
                                    />
                                @endif
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">#</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->deliveryNotes as $note)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                data-view-url="{{ route('delivery-notes.show', $note) }}"
                                data-edit-url="{{ route('delivery-notes.edit', $note) }}"
                                data-email-modal="email-document-{{ $note->id }}"
                                @if($note->status === DocumentStatus::Active) data-convert-modal="convert-dn-index-{{ $note->id }}" @endif
                                data-delete-modal="delete-document-{{ $note->id }}"
                                class="transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5"
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-4">
                                    @if($note->status === DocumentStatus::Active)
                                        <input
                                            type="checkbox"
                                            value="{{ $note->id }}"
                                            wire:model.live="selectedIds"
                                            class="size-4 cursor-pointer rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-600 dark:bg-zinc-800"
                                        />
                                    @endif
                                </td>
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
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $note->status->ringColor() }}">
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
                                            :class="$note->has_been_emailed
                                                ? '!text-emerald-600 hover:!text-emerald-700 dark:!text-emerald-400'
                                                : '!text-amber-500 hover:!text-amber-600 dark:!text-amber-400'"
                                            :title="$note->has_been_emailed ? __('Email sent') : __('Not yet emailed')"
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
