<?php

use App\DocumentType;
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

    /**
     * Stack of document IDs toggled on via search-result clicks, newest first.
     * Plain component state (NOT #[Url]) so it survives search changes;
     * only touched by toggleDocument()/removeDocument()/clearSelected().
     *
     * @var array<int, int>
     */
    public array $selectedDocumentIds = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function items()
    {
        $term = trim($this->search);

        if ($term === '') {
            return DocumentItem::query()->whereRaw('1 = 0')->paginate($this->perPage);
        }

        return DocumentItem::query()
            ->where('is_note', false)
            ->where('details', 'like', "%{$term}%")
            ->whereHas('document', fn ($q) => $q->whereIn('type', [
                DocumentType::DeliveryNote,
                DocumentType::Invoice,
            ]))
            ->with(['document.customer', 'document.assignee'])
            ->orderByDesc('id')
            ->paginate($this->perPage);
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
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900">
        <div x-data="zoneNav('search')" data-zone="search" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg max-w-sm">
            <flux:input
                wire:model.live.debounce.300ms="search"
                data-search-input
                autocomplete="off"
                icon="magnifying-glass"
                :placeholder="__('Search line item details…')"
                clearable
            />
        </div>
    </div>

    {{-- Results --}}
    <x-ui.section-card title="Results" :padding="false">
        @if(trim($search) === '')
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
                    <thead class="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800/50">
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
                    <thead class="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800/50">
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
