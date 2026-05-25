<?php

use App\DocumentStatus;
use App\DocumentType;
use App\Livewire\Concerns\WithSorting;
use App\Models\Document;
use App\Models\DocumentEmailLog;
use App\Services\DocumentEmailService;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Invoices')] class extends Component {
    use WithPagination, WithSorting;

    protected array $sortable = ['doc_number', 'doc_date', 'status', 'created_at'];

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public bool $trashed = false;

    /** @var array<int, int> */
    public array $selectedIds = [];

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedTrashed(): void
    {
        $this->resetPage();
    }

    #[On('document-deleted')]
    public function refreshList(): void
    {
        $this->resetPage();
    }

    public function restore(int $id): void
    {
        $invoice = Document::onlyTrashed()->invoices()->findOrFail($id);

        DB::transaction(function () use ($invoice) {
            $invoice->restore();

            $source = $invoice->convertedFrom;
            if ($source && $source->status === DocumentStatus::Active) {
                $sourceHasOtherActiveInvoice = Document::invoices()
                    ->where('converted_from_id', $source->id)
                    ->where('id', '!=', $invoice->id)
                    ->exists();

                if (! $sourceHasOtherActiveInvoice) {
                    $source->update(['status' => DocumentStatus::Converted]);
                }
            }
        });

        Flux::toast(variant: 'success', text: __('Invoice :number restored.', ['number' => $invoice->doc_number]));
    }

    /** @return \Illuminate\Support\Collection<int, Document> */
    #[Computed]
    public function selectedForEmail()
    {
        if (empty($this->selectedIds)) {
            return collect();
        }

        return Document::invoices()
            ->with('customer')
            ->whereIn('id', $this->selectedIds)
            ->get();
    }

    public function bulkEmail(DocumentEmailService $service): void
    {
        if (empty($this->selectedIds)) {
            return;
        }

        $invoices = Document::invoices()
            ->with('customer')
            ->whereIn('id', $this->selectedIds)
            ->get();

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($invoices as $invoice) {
            $email = $invoice->customer?->email_1;

            if (! $email) {
                $skipped++;

                continue;
            }

            try {
                $service->send($invoice, $email);
                $sent++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->selectedIds = [];

        Flux::modal('bulk-email-invoices')->close();

        $message = match (true) {
            $sent > 0 && ($skipped > 0 || $failed > 0) => __(':n sent, :s skipped, :f failed.', ['n' => $sent, 's' => $skipped, 'f' => $failed]),
            $sent > 0 => __(':n email(s) sent.', ['n' => $sent]),
            default => __('No emails sent.'),
        };

        Flux::toast(variant: $sent > 0 ? 'success' : 'warning', text: $message);
    }

    #[Computed]
    public function invoices()
    {
        return Document::invoices()
            ->when($this->trashed, fn ($q) => $q->onlyTrashed())
            ->with('customer')
            ->addSelect([
                'last_email_status' => DocumentEmailLog::select('status')
                    ->whereColumn('document_id', 'documents.id')
                    ->latest('id')
                    ->limit(1),
                'last_email_error' => DocumentEmailLog::select('error_message')
                    ->whereColumn('document_id', 'documents.id')
                    ->latest('id')
                    ->limit(1),
            ])
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('doc_number', 'like', "%{$this->search}%")
                    ->orWhereHas('customer', fn ($q) => $q->where('company_name', 'like', "%{$this->search}%"));
            }))
            ->when($this->status && ! $this->trashed, fn ($q) => $q->where('status', $this->status))
            ->tap(fn ($q) => $this->applySort($q))
            ->when($this->sortColumn === '', fn ($q) => $q->latest())
            ->paginate(15);
    }

    /** @return array<int, int> */
    #[Computed]
    public function selectableIdsOnPage(): array
    {
        if ($this->trashed) {
            return [];
        }

        return $this->invoices->pluck('id')->all();
    }

    #[Computed]
    public function pageFullySelected(): bool
    {
        $ids = $this->selectableIdsOnPage;

        return ! empty($ids) && empty(array_diff($ids, $this->selectedIds));
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Invoices"
        subtitle="Manage and track all invoices."
    />

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div x-data="zoneNav('search')" data-zone="search" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg flex-1 max-w-sm">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    data-search-input
                    autocomplete="off"
                    icon="magnifying-glass"
                    :placeholder="__('Search by invoice number or customer…')"
                    clearable
                    class="flex-1 max-w-sm"
                />
            </div>

            {{-- Status filter pills --}}
            <div x-data="zoneNav('filters')" data-zone="filters" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg flex flex-wrap gap-1.5">
                @foreach(['' => 'All', 'active' => 'Active', 'emailed' => 'Emailed'] as $val => $label)
                    <button
                        type="button"
                        wire:click="$set('status', '{{ $val }}'); $set('trashed', false)"
                        data-filter-pill
                        @class([
                            'rounded-full px-3 py-1 text-xs font-medium transition-colors',
                            'bg-indigo-600 text-white shadow-sm' => ! $trashed && $status === $val,
                            'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/15' => $trashed || $status !== $val,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
                <button
                    type="button"
                    wire:click="$toggle('trashed')"
                    data-filter-pill
                    @class([
                        'inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium transition-colors',
                        'bg-rose-600 text-white shadow-sm' => $trashed,
                        'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/15' => ! $trashed,
                    ])
                >
                    <flux:icon.trash class="size-3" />
                    {{ __('Trash') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Bulk action bar --}}
    @if(! $trashed && count($selectedIds) > 0)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 shadow-[0_1px_2px_rgba(16,24,40,0.06)] dark:border-indigo-500/20 dark:bg-indigo-500/10">
            <div class="flex items-center gap-2 text-sm">
                <flux:icon.check-circle class="size-4 text-indigo-600 dark:text-indigo-400" />
                <span class="font-medium text-indigo-900 dark:text-indigo-200">
                    {{ trans_choice(':count invoice selected|:count invoices selected', count($selectedIds), ['count' => count($selectedIds)]) }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="ghost" wire:click="clearSelection">
                    {{ __('Clear') }}
                </flux:button>
                <flux:button
                    size="sm"
                    variant="primary"
                    icon="envelope"
                    x-on:click="$flux.modal('bulk-email-invoices').show()"
                >
                    {{ __('Email Selected') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Table card --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">

        @if($this->invoices->isEmpty())
            <x-ui.empty-state
                :icon="$trashed ? 'trash' : 'document-text'"
                :title="$trashed ? 'Trash is empty' : 'No invoices found'"
                :description="$trashed ? 'Deleted invoices will appear here.' : (($search || $status) ? 'Try adjusting your search or filters.' : 'Invoices are created by converting a delivery note.')"
            >
                @unless($trashed || $search || $status)
                    <x-slot:action>
                        <flux:button variant="primary" :href="route('delivery-notes.index')" wire:navigate>
                            Go to Delivery Notes
                        </flux:button>
                    </x-slot:action>
                @endunless
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg" x-data="zoneNav('table')" data-zone="table" tabindex="-1">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="w-10 px-4 py-2">
                                @if(! $trashed && count($this->selectableIdsOnPage) > 0)
                                    <input
                                        type="checkbox"
                                        @checked($this->pageFullySelected)
                                        x-on:change="$wire.set('selectedIds', $event.target.checked ? Array.from(new Set([...$wire.selectedIds, ...{{ json_encode($this->selectableIdsOnPage) }}])) : $wire.selectedIds.filter(id => !{{ json_encode($this->selectableIdsOnPage) }}.includes(id)))"
                                        class="size-4 cursor-pointer rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-600 dark:bg-zinc-800"
                                        title="{{ __('Select all on this page') }}"
                                    />
                                @endif
                            </th>
                            <x-ui.sortable-header column="doc_number" :state="$this->sortStateFor('doc_number')">#</x-ui.sortable-header>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Customer</th>
                            <x-ui.sortable-header column="doc_date" :state="$this->sortStateFor('doc_date')">Date</x-ui.sortable-header>
                            <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount</th>
                            <x-ui.sortable-header column="status" :state="$this->sortStateFor('status')">Status</x-ui.sortable-header>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->invoices as $invoice)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                data-view-url="{{ route('invoices.show', $invoice) }}"
                                data-edit-url="{{ route('invoices.edit', $invoice) }}"
                                data-email-modal="email-document-{{ $invoice->id }}"
                                class="transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5"
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2">
                                    @if(! $trashed)
                                        <input
                                            type="checkbox"
                                            value="{{ $invoice->id }}"
                                            wire:model.live="selectedIds"
                                            class="size-4 cursor-pointer rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-600 dark:bg-zinc-800"
                                        />
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <a href="{{ route('invoices.show', $invoice) }}" wire:navigate @class([
                                        'inline-flex items-center rounded-md px-2 py-0.5 font-mono text-sm font-semibold text-indigo-700 hover:underline dark:text-indigo-300',
                                        'bg-emerald-100 dark:bg-emerald-500/20' => $invoice->last_email_status === 'sent',
                                        'bg-rose-100 dark:bg-rose-500/20' => $invoice->last_email_status === 'failed',
                                        'bg-amber-100 dark:bg-amber-500/20' => $invoice->last_email_status === null,
                                    ])>
                                        <x-ui.highlight :text="$invoice->doc_number" :term="$search" />
                                    </a>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2.5">
                                        <x-ui.avatar :name="$invoice->customer->company_name" size="xs" />
                                        <span class="font-medium text-zinc-900 dark:text-white"><x-ui.highlight :text="$invoice->customer->company_name" :term="$search" /></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-zinc-500 dark:text-zinc-400">{{ $invoice->doc_date->format('d M Y') }}</td>
                                <td class="px-6 py-4 text-right font-mono tabular-nums font-semibold text-zinc-900 dark:text-white">£{{ number_format($invoice->total_value, 2) }}</td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $invoice->status->ringColor() }}">
                                        {{ $invoice->status->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($trashed)
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-uturn-left"
                                                wire:click="restore({{ $invoice->id }})"
                                                class="text-emerald-600 hover:text-emerald-700"
                                                data-row-action="restore"
                                            >
                                                {{ __('Restore') }}
                                            </flux:button>
                                        @else
                                            <flux:button size="xs" variant="ghost" icon="eye" :href="route('invoices.show', $invoice)" wire:navigate data-row-action="view" />
                                            <flux:button size="xs" variant="ghost" icon="pencil" :href="route('invoices.edit', $invoice)" wire:navigate data-row-action="edit" />
                                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('documents.pdf.download', $invoice)" data-row-action="download" />
                                            <span class="relative inline-flex">
                                                <flux:button
                                                    size="xs"
                                                    variant="ghost"
                                                    icon="envelope"
                                                    x-on:click="$flux.modal('email-document-{{ $invoice->id }}').show()"
                                                    @class([
                                                        '!text-emerald-600 hover:!text-emerald-700 dark:!text-emerald-400' => $invoice->last_email_status === 'sent',
                                                        '!text-rose-600 hover:!text-rose-700 dark:!text-rose-400' => $invoice->last_email_status === 'failed',
                                                        '!text-amber-500 hover:!text-amber-600 dark:!text-amber-400' => $invoice->last_email_status === null,
                                                    ])
                                                    title="{{ match($invoice->last_email_status) {
                                                        'sent' => __('Email sent'),
                                                        'failed' => __('Last send failed: :msg', ['msg' => $invoice->last_email_error ?? 'unknown error']),
                                                        default => __('Not yet emailed'),
                                                    } }}"
                                                    data-row-action="email"
                                                />
                                                @if($invoice->last_email_status === 'failed')
                                                    <span class="pointer-events-none absolute -top-0.5 -right-0.5 flex h-2.5 w-2.5">
                                                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-75"></span>
                                                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white dark:ring-zinc-900"></span>
                                                    </span>
                                                @endif
                                            </span>
                                            <livewire:pages::invoices.delete-modal :document="$invoice" :key="'delete-'.$invoice->id" />
                                            <livewire:pages::documents.email-modal :document="$invoice" :key="'email-'.$invoice->id" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-100 px-4 py-2 dark:border-white/[0.06] outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30" x-data="zoneNav('pagination')" data-zone="pagination" tabindex="-1">
                {{ $this->invoices->links() }}
            </div>
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

    {{-- Modals --}}
    @if(! $trashed && count($selectedIds) > 0)
        <flux:modal name="bulk-email-invoices" focusable class="max-w-lg">
            <div class="flex max-h-[80vh] flex-col gap-4">
                <div class="shrink-0">
                    <flux:heading size="lg">
                        {{ trans_choice('Send :count email?|Send :count emails?', count($selectedIds), ['count' => count($selectedIds)]) }}
                    </flux:heading>
                    <flux:subheading>
                        {{ __('Each selected invoice will be emailed to the customer below. Rows without a customer email are skipped.') }}
                    </flux:subheading>
                </div>
                <ul class="min-h-0 flex-1 overflow-y-auto rounded-lg border border-zinc-200 divide-y divide-zinc-100 dark:border-white/10 dark:divide-white/[0.06]">
                    @foreach($this->selectedForEmail as $doc)
                        <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="font-mono text-zinc-900 dark:text-white">{{ $doc->doc_number }}</span>
                            <span class="flex-1 truncate text-zinc-600 dark:text-zinc-300">{{ $doc->customer?->company_name ?? '—' }}</span>
                            @if($doc->customer?->email_1)
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $doc->customer->email_1 }}</span>
                            @else
                                <span class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ __('no email — skipped') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="flex shrink-0 justify-end gap-3">
                    <flux:modal.close>
                        <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button
                        variant="primary"
                        icon="envelope"
                        wire:click="bulkEmail"
                        wire:loading.attr="disabled"
                        wire:target="bulkEmail"
                    >
                        <span wire:loading.remove wire:target="bulkEmail">{{ __('Send All') }}</span>
                        <span wire:loading wire:target="bulkEmail">{{ __('Sending…') }}</span>
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

</div>
