<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Setting;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Traits\WithPerPage;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Supplier Invoices')] class extends Component {
    use WithPagination;
    use WithSorting;
    use WithPerPage;

    protected array $sortable = ['supplier_invoice_no', 'invoice_date', 'created_at', 'gross_total', 'vat_total', 'net_total'];

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

    protected function applyCustomSort(Builder $query, string $column, string $direction): ?Builder
    {
        $rate = (float) Setting::get('vat_rate', 20);

        $grossSub = SupplierInvoiceItem::selectRaw('COALESCE(SUM(line_total), 0)')
            ->whereColumn('supplier_invoice_id', 'supplier_invoices.id');

        $vatSub = SupplierInvoiceItem::selectRaw(
            'COALESCE(SUM(CASE WHEN vat_applicable = 1 THEN line_total * ? / (100 + ?) ELSE 0 END), 0)',
            [$rate, $rate]
        )->whereColumn('supplier_invoice_id', 'supplier_invoices.id');

        $netRate = 100 / (100 + $rate);

        $netSub = SupplierInvoiceItem::selectRaw(
            'COALESCE(SUM(line_total * CASE WHEN vat_applicable = 0 THEN 1 ELSE ? END), 0)',
            [$netRate]
        )->whereColumn('supplier_invoice_id', 'supplier_invoices.id');

        return match ($column) {
            'gross_total' => $query->orderBy($grossSub, $direction),
            'vat_total' => $query->orderBy($vatSub, $direction),
            'net_total' => $query->orderBy($netSub, $direction),
            default => null,
        };
    }

    public function openDeleteModal(int $id): void
    {
        $this->deletingId = $id;
        $this->js("\$flux.modal('delete-supplier-invoice-{$id}').show()");
    }

    #[On('supplier-invoice-deleted')]
    public function onDeleted(): void
    {
        $this->deletingId = null;
    }

    #[Computed]
    public function supplierInvoices()
    {
        return SupplierInvoice::with(['supplier', 'items'])
            ->when($this->search, function ($q) {
                $q->where('supplier_invoice_no', 'like', "%{$this->search}%")
                    ->orWhereHas('supplier', fn ($q) => $q->where('company_name', 'like', "%{$this->search}%"));
            })
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('invoice_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('invoice_date', '<=', $this->dateTo))
            ->when($this->amountMin !== '' && is_numeric($this->amountMin), fn ($q) => $q->where(
                SupplierInvoiceItem::selectRaw('SUM(line_total)')
                    ->whereColumn('supplier_invoice_id', 'supplier_invoices.id'),
                '>=',
                (float) $this->amountMin
            ))
            ->when($this->amountMax !== '' && is_numeric($this->amountMax), fn ($q) => $q->where(
                SupplierInvoiceItem::selectRaw('SUM(line_total)')
                    ->whereColumn('supplier_invoice_id', 'supplier_invoices.id'),
                '<=',
                (float) $this->amountMax
            ))
            ->tap(fn ($q) => $this->applySort($q))
            ->when($this->sortColumn === '', fn ($q) => $q->orderByDesc('invoice_date'))
            ->paginate($this->perPage);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Supplier Invoices"
        subtitle="Manage invoices received from suppliers."
    >
        <x-slot:action>
            <flux:button variant="primary" icon="plus" :href="route('supplier-invoices.create')" wire:navigate>
                New Invoice
            </flux:button>
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
                    :placeholder="__('Search by invoice no or supplier…')"
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

        @if($this->supplierInvoices->isEmpty())
            <x-ui.empty-state
                icon="receipt-percent"
                title="No supplier invoices found"
                :description="($search || $dateFrom || $dateTo || $amountMin || $amountMax) ? 'Try adjusting your search or filters.' : 'Get started by recording your first supplier invoice.'"
            >
                @unless($search || $dateFrom || $dateTo || $amountMin || $amountMax)
                    <x-slot:action>
                        <flux:button variant="primary" :href="route('supplier-invoices.create')" wire:navigate>
                            New Invoice
                        </flux:button>
                    </x-slot:action>
                @endunless
            </x-ui.empty-state>
        @else
            <div x-data="zoneNav('table')" data-zone="table" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30">
                <table class="w-full text-sm">
                    <thead class="sticky top-14 lg:top-16 z-10 bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <x-ui.sortable-header column="supplier_invoice_no" :state="$this->sortStateFor('supplier_invoice_no')">#</x-ui.sortable-header>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Supplier</th>
                            <x-ui.sortable-header column="invoice_date" :state="$this->sortStateFor('invoice_date')">Date</x-ui.sortable-header>
                            <x-ui.sortable-header column="net_total" align="right" :state="$this->sortStateFor('net_total')">Net (£)</x-ui.sortable-header>
                            <x-ui.sortable-header column="vat_total" align="right" :state="$this->sortStateFor('vat_total')">VAT (£)</x-ui.sortable-header>
                            <x-ui.sortable-header column="gross_total" align="right" :state="$this->sortStateFor('gross_total')">Gross (£)</x-ui.sortable-header>
                            <th class="px-4 py-1"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->supplierInvoices as $invoice)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                data-view-url="{{ route('supplier-invoices.show', $invoice) }}"
                                data-edit-url="{{ route('supplier-invoices.edit', $invoice) }}"
                                @class([
                                    'transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5',
                                    'sticky bottom-0 z-10 bg-white dark:bg-zinc-900 shadow-[0_-1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_-1px_0_0_theme(--color-white/0.06)]' => $loop->last,
                                ])
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2">
                                    <a href="{{ route('supplier-invoices.show', $invoice) }}" wire:navigate class="font-mono text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                                        {{ $invoice->supplier_invoice_no }}
                                    </a>
                                </td>
                                <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">
                                    {{ $invoice->supplier?->company_name ?? '—' }}
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">
                                    {{ $invoice->invoice_date->format('d M Y') }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-900 dark:text-white">
                                    {{ number_format($invoice->netTotal, 2) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">
                                    {{ number_format($invoice->vatTotal, 2) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-900 dark:text-white">
                                    {{ number_format($invoice->grossTotal, 2) }}
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        @if(count($invoice->attachments ?? []) === 1)
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-down-tray"
                                                :href="\Illuminate\Support\Facades\Storage::disk('public')->url(($invoice->attachments)[0]['path'])"
                                                title="Download attachment"
                                                data-row-action="download"
                                            />
                                        @elseif(count($invoice->attachments ?? []) > 1)
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="archive-box-arrow-down"
                                                :href="route('supplier-invoices.attachments.download', $invoice)"
                                                title="Download {{ count($invoice->attachments) }} attachments"
                                                data-row-action="download"
                                            />
                                        @endif
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('supplier-invoices.show', $invoice)" wire:navigate data-row-action="view" />
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('supplier-invoices.edit', $invoice)" wire:navigate data-row-action="edit" />
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="trash"
                                            wire:click="openDeleteModal({{ $invoice->id }})"
                                            class="text-rose-500 hover:text-rose-600"
                                            data-row-action="delete"
                                        />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Footer: pagination --}}
            <flux:pagination :paginator="$this->supplierInvoices" class="px-6" />
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

    {{-- Delete modal --}}
    @if($deletingId)
        <livewire:pages::supplier-invoices.delete-modal
            :supplier-invoice="App\Models\SupplierInvoice::find($deletingId)"
            :key="$deletingId"
        />
    @endif

</div>
