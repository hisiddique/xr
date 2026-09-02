<?php

use App\Models\SupplierDebitNote;
use App\Traits\WithPerPage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Flux\Flux;

new #[Title('Supplier Debit Notes')] class extends Component {
    use WithPagination;
    use WithPerPage;

    #[Url]
    public string $search = '';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'min', except: '')]
    public string $amountMin = '';

    #[Url(as: 'max', except: '')]
    public string $amountMax = '';

    public string $sortBy = 'reference';

    public string $sortDir = 'desc';

    public int $perPage = 25;

    public ?int $deletingId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedAmountMin(): void
    {
        $this->resetPage();
    }

    public function updatedAmountMax(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->amountMin = '';
        $this->amountMax = '';
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        SupplierDebitNote::findOrFail($id)->delete();
        Flux::toast('Debit note deleted.');
        $this->deletingId = null;
    }

    #[Computed]
    public function debitNotes()
    {
        return SupplierDebitNote::with(['supplier', 'supplierInvoice'])
            ->when($this->search, fn ($q) => $q->where(fn ($q2) =>
                $q2->where('reference', 'like', '%'.$this->search.'%')
                   ->orWhereHas('supplier', fn ($s) => $s->where('company_name', 'like', '%'.$this->search.'%'))
                   ->orWhereHas('supplierInvoice', fn ($i) => $i->where('supplier_ref_invoice_no', 'like', '%'.$this->search.'%'))
            ))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('doc_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('doc_date', '<=', $this->dateTo))
            ->when($this->amountMin !== '', fn ($q) => $q->where('total', '>=', $this->amountMin))
            ->when($this->amountMax !== '', fn ($q) => $q->where('total', '<=', $this->amountMax))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate($this->perPage);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Supplier Debit Notes"
        subtitle="Manage supplier debit notes."
    >
        <x-slot:action>
            @can('supplierdebitnote-create')
            <flux:button variant="primary" icon="plus" :href="route('supplier-debit-notes.create')" wire:navigate>
                New Debit Note
            </flux:button>
            @endcan
        </x-slot:action>
    </x-ui.page-header>

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900 flex flex-col gap-3">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div x-data="zoneNav('search')" data-zone="search" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg flex-1 max-w-sm">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    data-search-input
                    autocomplete="off"
                    icon="magnifying-glass"
                    :placeholder="__('Search by reference, supplier or invoice ref…')"
                    clearable
                />
            </div>
            <x-ui.per-page-select class="ml-auto" />
        </div>

        <x-ui.range-filters
            :date-from="$dateFrom"
            :date-to="$dateTo"
            :amount-min="$amountMin"
            :amount-max="$amountMax"
        />
    </div>

    {{-- Table card --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">

        @if($this->debitNotes->isEmpty())
            <x-ui.empty-state
                icon="document-minus"
                title="No supplier debit notes found"
                :description="($search || $dateFrom || $dateTo || $amountMin || $amountMax) ? 'Try adjusting your search or filters.' : 'Get started by recording your first supplier debit note.'"
            >
                @unless($search || $dateFrom || $dateTo || $amountMin || $amountMax)
                    <x-slot:action>
                        @can('supplierdebitnote-create')
                        <flux:button variant="primary" :href="route('supplier-debit-notes.create')" wire:navigate>
                            New Debit Note
                        </flux:button>
                        @endcan
                    </x-slot:action>
                @endunless
            </x-ui.empty-state>
        @else
            <div x-data="zoneNav('table')" data-zone="table" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30">
                <table class="w-full text-sm">
                    <thead class="sticky top-14 lg:top-16 z-10 bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Reference</th>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Supplier</th>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Invoice Linked</th>
                            <th class="px-4 py-1 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total (£)</th>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Applied</th>
                            <th class="px-4 py-1"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->debitNotes as $note)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                @can('supplierdebitnote-show') data-view-url="{{ route('supplier-debit-notes.show', $note) }}" @endcan
                                @can('supplierdebitnote-edit') data-edit-url="{{ route('supplier-debit-notes.edit', $note) }}" @endcan
                                @class([
                                    'transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5',
                                    'sticky bottom-0 z-10 bg-white dark:bg-zinc-900 shadow-[0_-1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_-1px_0_0_theme(--color-white/0.06)]' => false && $loop->last, // sticky first/last row disabled
                                    'sticky top-[5.75rem] lg:top-[6.25rem] z-10 bg-white dark:bg-zinc-900 shadow-[0_1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_1px_0_0_theme(--color-white/0.06)]' => false && $loop->first,
                                ])
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2">
                                    @can('supplierdebitnote-show')
                                    <a href="{{ route('supplier-debit-notes.show', $note) }}" wire:navigate class="font-mono text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                                        {{ $note->reference }}
                                    </a>
                                    @else
                                    <span class="font-mono">
                                        {{ $note->reference }}
                                    </span>
                                    @endcan
                                </td>
                                <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">
                                    {{ $note->supplier?->company_name ?? '—' }}
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">
                                    {{ $note->doc_date->format('d M Y') }}
                                </td>
                                <td class="px-4 py-2 font-mono text-zinc-600 dark:text-zinc-400">
                                    @if($note->supplier_invoice_id && $note->supplierInvoice)
                                        {{ $note->supplierInvoice->supplier_invoice_no }}@if($note->supplierInvoice->supplier_ref_invoice_no) ({{ $note->supplierInvoice->supplier_ref_invoice_no }})@endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-900 dark:text-white">
                                    {{ number_format((float) $note->total, 2) }}
                                </td>
                                <td class="px-4 py-2">
                                    @if($note->isApplied())
                                        <flux:badge color="green" size="sm">Yes</flux:badge>
                                    @else
                                        <span class="text-zinc-400 dark:text-zinc-500">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('supplierdebitnote-show')
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('supplier-debit-notes.show', $note)" wire:navigate data-row-action="view" />
                                        @endcan
                                        @can('supplierdebitnote-edit')
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('supplier-debit-notes.edit', $note)" wire:navigate data-row-action="edit" />
                                        @endcan
                                        @can('supplierdebitnote-delete')
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="trash"
                                            wire:click="$set('deletingId', {{ $note->id }})"
                                            @click="$flux.modal('delete-debit-note').show()"
                                            class="text-rose-500 hover:text-rose-600"
                                            data-row-action="delete"
                                        />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Footer: pagination --}}
            <flux:pagination :paginator="$this->debitNotes" class="px-6" />
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

    {{-- Delete modal --}}
    <flux:modal name="delete-debit-note" class="min-w-[22rem]">
        <div class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">Delete debit note?</flux:heading>
                <flux:subheading>This action cannot be undone.</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button x-on:click="$flux.modal('delete-debit-note').close()">Cancel</flux:button>
                <flux:button variant="danger" wire:click="delete(deletingId)" @click="$flux.modal('delete-debit-note').close()">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
