<?php

use App\DocumentType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Traits\WithPerPage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Document Search')] class extends Component
{
    use WithPagination;
    use WithPerPage;

    public int $perPage = 25;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $customerId = null;

    public string $customerName = '';

    #[Url]
    public array $invoiceIds = [];

    public string $invoiceSearch = '';

    #[Url]
    public ?string $dateFrom = null;

    #[Url]
    public ?string $dateTo = null;

    /**
     * Stack of document IDs toggled on via search-result clicks, newest first.
     * Plain component state (NOT #[Url]) so it survives search changes;
     * only touched by toggleDocument()/removeDocument()/clearSelected().
     *
     * @var array<int, int>
     */
    public array $selectedDocumentIds = [];

    public function mount(): void
    {
        if ($this->customerId !== null) {
            $this->customerName = Customer::withTrashed()->find($this->customerId)?->typeahead_label ?? '';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCustomerId(): void
    {
        $this->invoiceIds = [];
        $this->invoiceSearch = '';
        $this->resetPage();
    }

    public function addInvoice(int $invoiceId): void
    {
        if (! in_array($invoiceId, $this->invoiceIds, true)) {
            $this->invoiceIds[] = $invoiceId;
        }

        $this->invoiceSearch = '';
        $this->resetPage();
    }

    public function removeInvoice(int $invoiceId): void
    {
        $this->invoiceIds = array_values(array_filter(
            $this->invoiceIds,
            fn ($id) => (int) $id !== $invoiceId
        ));
    }

    /** Server-searched invoice suggestions for the picker dropdown, excluding already-selected invoices. */
    #[Computed]
    public function invoiceSuggestions()
    {
        $term = trim($this->invoiceSearch);

        if ($this->customerId === null || $term === '') {
            return collect();
        }

        return Document::query()
            ->invoices()
            ->where('customer_id', $this->customerId)
            ->where('doc_number', 'like', "%{$term}%")
            ->when(! empty($this->invoiceIds), fn ($q) => $q->whereNotIn('id', $this->invoiceIds))
            ->orderByDesc('doc_date')
            ->limit(10)
            ->get(['id', 'doc_number', 'doc_date', 'total_value']);
    }

    /** Selected invoices, resolved regardless of the current invoice search term so chips stay visible. */
    #[Computed]
    public function selectedInvoices()
    {
        if (empty($this->invoiceIds)) {
            return collect();
        }

        return Document::query()
            ->whereIn('id', $this->invoiceIds)
            ->orderByDesc('doc_date')
            ->get(['id', 'doc_number', 'doc_date']);
    }

    protected function hasActiveFilters(): bool
    {
        return $this->customerId !== null
            || ! empty($this->invoiceIds)
            || $this->dateFrom !== null
            || $this->dateTo !== null;
    }

    #[Computed]
    public function items()
    {
        $term = trim($this->search);

        if ($term === '' && ! $this->hasActiveFilters()) {
            return DocumentItem::query()->whereRaw('1 = 0')->paginate($this->perPage);
        }

        return DocumentItem::query()
            ->where('is_note', false)
            ->when($term !== '', fn ($q) => $q->where('details', 'like', "%{$term}%"))
            ->whereHas('document', fn ($q) => $q->whereIn('type', [
                DocumentType::DeliveryNote,
                DocumentType::Invoice,
            ]))
            ->when($this->customerId, fn ($q) => $q->whereHas('document', fn ($q) => $q->where('customer_id', $this->customerId)))
            ->when(! empty($this->invoiceIds), fn ($q) => $q->whereIn('document_id', $this->invoiceIds))
            ->when($this->dateFrom, fn ($q) => $q->whereHas('document', fn ($q) => $q->whereDate('doc_date', '>=', $this->dateFrom)))
            ->when($this->dateTo, fn ($q) => $q->whereHas('document', fn ($q) => $q->whereDate('doc_date', '<=', $this->dateTo)))
            ->with(['document.customer', 'document.assignee'])
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    public function clearFilters(): void
    {
        $this->customerId = null;
        $this->customerName = '';
        $this->invoiceIds = [];
        $this->invoiceSearch = '';
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->search = '';
        $this->resetPage();
    }

    /** Resolved Document models for the bottom panel, in stack order (newest first). */
    #[Computed]
    public function selectedDocuments()
    {
        if (empty($this->selectedDocumentIds)) {
            return collect();
        }

        $docs = Document::with(['customer', 'assignee'])
            ->whereIn('id', $this->selectedDocumentIds)
            ->get()
            ->keyBy('id');

        return collect($this->selectedDocumentIds)
            ->map(fn ($id) => $docs->get($id))
            ->filter()
            ->values();
    }

    public function toggleDocument(int $documentId): void
    {
        if (in_array($documentId, $this->selectedDocumentIds, true)) {
            $this->removeDocument($documentId);

            return;
        }

        array_unshift($this->selectedDocumentIds, $documentId);
    }

    public function removeDocument(int $documentId): void
    {
        $this->selectedDocumentIds = array_values(array_filter(
            $this->selectedDocumentIds,
            fn ($id) => (int) $id !== $documentId
        ));
    }

    public function clearSelected(): void
    {
        $this->selectedDocumentIds = [];
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Document Search"
        subtitle="Search delivery note and invoice line items by detail text."
    />

    {{-- Search bar --}}
    @php
        $activeFilterCount = ($customerId ? 1 : 0) + count($invoiceIds) + ($dateFrom ? 1 : 0) + ($dateTo ? 1 : 0);
    @endphp
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-4 dark:border-white/10 dark:bg-zinc-900">
        <div class="flex flex-wrap items-end gap-x-4 gap-y-3">
            <div class="min-w-96 flex-1 max-w-xl">
                <livewire:pages::ui.typeahead
                    :key="'typeahead-customer'"
                    wire:model.live="customerId"
                    model="App\Models\Customer"
                    column="company_name"
                    :search-columns="['company_name', 'first_name', 'last_name', 'reference']"
                    label-accessor="typeahead_label"
                    :min-chars="2"
                    :label="__('Customer')"
                    :placeholder="__('Search customer…')"
                    :selected-label="$customerName"
                />
            </div>

            <flux:input wire:model.live="dateFrom" type="date" :label="__('From Date')" class="w-40" />
            <flux:input wire:model.live="dateTo" type="date" :label="__('To Date')" class="w-40" />

            <div class="hidden h-9 w-px self-center bg-zinc-200 sm:block dark:bg-white/10"></div>

            <div x-data="zoneNav('search')" data-zone="search" tabindex="-1" class="ml-auto min-w-56 flex-1 outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg max-w-sm">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    data-search-input
                    autocomplete="off"
                    icon="magnifying-glass"
                    :label="__('Search')"
                    :placeholder="__('Search line item details…')"
                    clearable
                />
            </div>

            @if($activeFilterCount > 0)
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters" title="{{ __('Reset Filters') }}" />
            @endif
        </div>

        @if($customerId)
            <div class="mt-4 border-t border-zinc-100 pt-4 dark:border-white/[0.06]">
                <flux:label>{{ __('Invoices') }}</flux:label>
                <div x-data="{ open: false }" x-on:click.outside="open = false" class="relative mt-1">
                    <div
                        class="flex min-h-[38px] flex-wrap items-center gap-1.5 rounded-lg border border-zinc-300 bg-zinc-50/60 px-2.5 py-1.5 focus-within:border-indigo-500 focus-within:bg-white focus-within:ring-1 focus-within:ring-indigo-500 dark:border-white/15 dark:bg-white/[0.03] dark:focus-within:bg-zinc-800"
                        x-on:click="$refs.invoiceSearchInput.focus()"
                    >
                        @foreach($this->selectedInvoices as $inv)
                            <span wire:key="selected-inv-{{ $inv->id }}" class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-500/20 dark:text-indigo-300 dark:ring-indigo-500/30">
                                {{ $inv->doc_number }}
                                <button type="button" wire:click.stop="removeInvoice({{ $inv->id }})" class="flex items-center text-indigo-400 hover:text-indigo-600 dark:hover:text-indigo-200">
                                    <svg class="size-3" viewBox="0 0 12 12" fill="none"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                </button>
                            </span>
                        @endforeach
                        <input
                            x-ref="invoiceSearchInput"
                            wire:model.live.debounce.300ms="invoiceSearch"
                            x-on:focus="open = true"
                            x-on:input="open = true"
                            type="text"
                            autocomplete="off"
                            placeholder="{{ $this->selectedInvoices->isEmpty() ? __('Search invoice number…') : '' }}"
                            class="min-w-[160px] flex-1 border-0 bg-transparent p-0 text-sm text-zinc-900 placeholder-zinc-400 outline-none focus:ring-0 dark:text-white"
                        />
                    </div>

                    @if(trim($invoiceSearch) !== '')
                        <div x-show="open" x-cloak x-transition.opacity class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-zinc-200 bg-white shadow-lg dark:border-white/10 dark:bg-zinc-900">
                            @if($this->invoiceSuggestions->isEmpty())
                                <div class="px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No matching invoices.') }}</div>
                            @else
                                @foreach($this->invoiceSuggestions as $inv)
                                    <button
                                        type="button"
                                        wire:key="suggestion-{{ $inv->id }}"
                                        wire:click="addInvoice({{ $inv->id }})"
                                        x-on:click="open = false"
                                        class="flex w-full cursor-pointer items-center gap-3 px-3 py-2 text-left text-sm text-zinc-900 transition-colors hover:bg-indigo-50 hover:text-indigo-700 focus:bg-indigo-50 focus:text-indigo-700 focus:outline-none dark:text-white dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300 dark:focus:bg-indigo-500/10 dark:focus:text-indigo-300"
                                    >
                                        <span class="font-mono font-semibold">{{ $inv->doc_number }}</span>
                                        <span class="text-zinc-400 dark:text-zinc-500">{{ $inv->doc_date->format('d M Y') }}</span>
                                        <span class="ml-auto font-mono">£{{ number_format($inv->total_value, 2) }}</span>
                                    </button>
                                @endforeach
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Results --}}
    <x-ui.section-card title="Results" :padding="false">
        @if(trim($search) === '' && ! ($customerId || ! empty($invoiceIds) || $dateFrom || $dateTo))
            <x-ui.empty-state
                icon="magnifying-glass"
                title="Start typing to search"
                description="Search across delivery note and invoice line items by their details text."
            />
        @elseif($this->items->isEmpty())
            <x-ui.empty-state
                icon="document-magnifying-glass"
                title="No matching line items"
                description="Try a different search term."
            />
        @else
            <div class="max-h-[50vh] overflow-y-auto overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Doc #</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Customer</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Details</th>
                            <th class="w-24 px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Qty</th>
                            <th class="w-28 px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Price</th>
                            <th class="w-24 px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Per</th>
                            <th class="w-28 px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Line Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->items as $item)
                            @php $isSelected = in_array($item->document_id, $selectedDocumentIds, true); @endphp
                            <tr
                                wire:click="toggleDocument({{ $item->document_id }})"
                                wire:key="item-{{ $item->id }}"
                                @class([
                                    'cursor-pointer transition-colors',
                                    'bg-indigo-50 dark:bg-indigo-500/10 hover:bg-indigo-100 dark:hover:bg-indigo-500/15' => $isSelected,
                                    'hover:bg-zinc-50 dark:hover:bg-white/[0.03]' => ! $isSelected,
                                ])
                            >
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-mono text-sm font-semibold text-indigo-700 dark:text-indigo-300">
                                        <span class="rounded bg-zinc-100 px-1 text-[10px] font-bold uppercase tracking-wide text-zinc-500 dark:bg-white/10 dark:text-zinc-400">
                                            {{ $item->document->type->value }}
                                        </span>
                                        {{ $item->document->doc_number }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $item->document->customer->company_name }}</td>
                                <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $item->document->doc_date->format('d M Y') }}</td>
                                <td class="px-4 py-2 font-semibold text-zinc-900 dark:text-white">
                                    <x-ui.highlight :text="$item->details" :term="$search" />
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-zinc-700 dark:text-zinc-300">{{ (float) $item->quantity !== 0.0 ? $item->quantity : '' }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-zinc-700 dark:text-zinc-300">{{ (float) $item->price !== 0.0 ? '£'.number_format($item->price, 2) : '' }}</td>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $item->per }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-semibold text-zinc-900 dark:text-white">{{ (float) $item->line_value !== 0.0 ? '£'.number_format($item->line_value, 2) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-100 px-4 py-3 dark:border-zinc-800">
                {{ $this->items->links() }}
            </div>
        @endif
    </x-ui.section-card>

    {{-- Bottom panel: accumulated documents --}}
    <x-ui.section-card :padding="false">
        <x-slot:header>
            <div class="flex w-full items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">
                    Collected Documents
                    @if($this->selectedDocuments->isNotEmpty())
                        <span class="ml-1 text-xs font-normal text-zinc-500 dark:text-zinc-400">({{ $this->selectedDocuments->count() }})</span>
                    @endif
                </h2>
                @if($this->selectedDocuments->isNotEmpty())
                    <flux:button size="sm" variant="ghost" wire:click="clearSelected">Clear all</flux:button>
                @endif
            </div>
        </x-slot:header>

        @if($this->selectedDocuments->isEmpty())
            <x-ui.empty-state
                icon="archive-box"
                title="No documents collected yet"
                description="Click a search result row to add its document here."
            />
        @else
            <div class="max-h-[35vh] overflow-y-auto overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">#</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Customer</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Order Ref</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Sales Person</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->selectedDocuments as $doc)
                            @php
                                $showRoute = $doc->type === DocumentType::DeliveryNote
                                    ? route('delivery-notes.show', $doc)
                                    : route('invoices.show', $doc);
                            @endphp
                            <tr
                                wire:key="selected-{{ $doc->id }}"
                                data-view-url="{{ $showRoute }}"
                                onclick="if (!event.target.closest('[data-row-action]')) window.location.href = this.dataset.viewUrl"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5"
                            >
                                <td class="px-4 py-2">
                                    <a href="{{ $showRoute }}" wire:navigate class="font-mono text-sm font-semibold text-indigo-700 hover:underline dark:text-indigo-300">
                                        {{ $doc->doc_number }}
                                    </a>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2.5">
                                        <x-ui.avatar :name="$doc->customer->company_name" size="xs" />
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $doc->customer->company_name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-300">{{ $doc->order_no ?: '—' }}</td>
                                <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $doc->doc_date->format('d M Y') }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-semibold text-zinc-900 dark:text-white">£{{ number_format($doc->total_value, 2) }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $doc->status->ringColor() }}">
                                        {{ $doc->status->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-300">{{ $doc->assignee?->name ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="x-mark"
                                        wire:click="removeDocument({{ $doc->id }})"
                                        data-row-action="remove"
                                        title="{{ __('Remove') }}"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.section-card>

</div>
