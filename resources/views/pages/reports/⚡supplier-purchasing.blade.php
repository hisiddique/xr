<?php

use App\ExportJobStatus;
use App\Jobs\SendSupplierPurchasingReportJob;
use App\Models\ExportJob;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\SupplierPurchasingReportService;
use App\Traits\WithPerPage;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Supplier Purchasing Report')] class extends Component
{
    use WithPagination;
    use WithPerPage;

    #[Url]
    public string $search = '';

    #[Url(as: 'supplier', except: '')]
    public string $supplierId = '';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'min', except: '')]
    public string $amountMin = '';

    #[Url(as: 'max', except: '')]
    public string $amountMax = '';

    #[Url(as: 'status', except: '')]
    public string $paidStatus = '';

    #[Url(except: 'this_month')]
    public string $period = 'this_month';

    #[Url(as: 'sort', except: 'company_name')]
    public string $sortBy = 'company_name';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDirection = 'asc';

    public int $perPage = 100;

    /** @var array<int, string> */
    public array $reportEmails = [];

    /** @var array<int, string> */
    public array $reportFormats = ['pdf'];

    public string $reportNotes = '';

    public function mount(): void
    {
        $this->resolvePeriod();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSupplierId(): void
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

    public function updatedPaidStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resolvePeriod();
        $this->resetPage();
    }

    protected function resolvePeriod(): void
    {
        [$from, $to] = match ($this->period) {
            'today' => [Carbon::today(), Carbon::today()],
            'yesterday' => [Carbon::yesterday(), Carbon::yesterday()],
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [now()->subMonths(2)->startOfMonth(), now()->endOfMonth()],
            'last_6_months' => [now()->subMonths(5)->startOfMonth(), now()->endOfMonth()],
            'this_year' => [now()->startOfYear(), now()->endOfYear()],
            default => [null, null],
        };

        if ($from !== null && $to !== null) {
            $this->dateFrom = $from->toDateString();
            $this->dateTo = $to->toDateString();
        }
    }

    public function sortByColumn(string $column): void
    {
        if ($column === $this->sortBy) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->supplierId = '';
        $this->amountMin = '';
        $this->amountMax = '';
        $this->paidStatus = '';
        $this->period = 'this_month';
        $this->resolvePeriod();
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function filters(): array
    {
        return [
            'search' => $this->search,
            'supplierId' => $this->supplierId !== '' ? (int) $this->supplierId : null,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'amountMin' => $this->amountMin,
            'amountMax' => $this->amountMax,
            'paidStatus' => $this->paidStatus,
            'sortBy' => $this->sortBy,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function exportUrl(string $format): string
    {
        $params = array_filter($this->filters(), fn ($value) => $value !== null && $value !== '');

        return route('reports.supplier-purchasing.export', array_merge(['format' => $format], $params));
    }

    public function printUrl(): string
    {
        $params = array_filter($this->filters(), fn ($value) => $value !== null && $value !== '');

        return route('reports.supplier-purchasing.export', array_merge(['format' => 'pdf', 'inline' => 1], $params));
    }

    #[Computed]
    public function suppliers()
    {
        return app(SupplierPurchasingReportService::class)
            ->suppliersQuery($this->filters())
            ->paginate($this->perPage);
    }

    #[Computed]
    public function summary(): array
    {
        return app(SupplierPurchasingReportService::class)->summary($this->filters());
    }

    public function outstanding(SupplierInvoice $invoice): float
    {
        return app(SupplierPurchasingReportService::class)->outstandingAmount($invoice);
    }

    public function deductionsOf(SupplierInvoice $invoice): float
    {
        return app(SupplierPurchasingReportService::class)->deductionsTotal($invoice);
    }

    public function netPayableOf(SupplierInvoice $invoice): float
    {
        return app(SupplierPurchasingReportService::class)->netPayable($invoice);
    }

    public function debitNoteRefsOf(SupplierInvoice $invoice): string
    {
        return app(SupplierPurchasingReportService::class)->debitNoteRefs($invoice);
    }

    #[Computed]
    public function selectedSupplierLabel(): string
    {
        if ($this->supplierId === '' || ! is_numeric($this->supplierId)) {
            return '';
        }

        return Supplier::find((int) $this->supplierId)?->typeahead_label ?? '';
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->supplierId !== '' || $this->amountMin !== '' || $this->amountMax !== '' || $this->paidStatus !== '' || $this->period !== 'this_month';
    }

    public function sendReportEmail(): void
    {
        $this->validate([
            'reportEmails' => 'required|array|min:1',
            'reportEmails.*' => 'email|max:254',
            'reportFormats' => 'required|array|min:1',
            'reportFormats.*' => 'in:pdf,xlsx,csv',
            'reportNotes' => 'nullable|string|max:2000',
        ]);

        $filters = $this->filters();

        $exportJob = ExportJob::create([
            'status' => ExportJobStatus::Pending,
            'type' => 'supplier_purchasing',
            'format' => 'email',
            'filters' => $filters,
            'rows_total' => app(SupplierPurchasingReportService::class)->suppliersQuery($filters)->count(),
            'created_by' => auth()->id(),
        ]);

        SendSupplierPurchasingReportJob::dispatch(
            $exportJob->id,
            $this->reportEmails,
            $this->reportFormats,
            $this->reportNotes ?: null,
        );

        Flux::modal('send-report')->close();
        Flux::toast(variant: 'success', text: __('Report queued — you will receive it by email shortly.'));
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Supplier Purchasing Report"
        subtitle="Posted supplier invoices grouped by supplier, with net/VAT/gross totals, debit-note deductions, and payment status."
    />

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <x-ui.stat-card
            label="Total Invoices"
            :value="number_format($this->summary['invoiceCount'])"
            icon="document-text"
            color="indigo"
        />
        <x-ui.stat-card
            label="Total Net"
            :value="'£' . number_format($this->summary['totalNet'], 2)"
            icon="banknotes"
            color="violet"
        />
        <x-ui.stat-card
            label="Total VAT"
            :value="'£' . number_format($this->summary['totalVat'], 2)"
            icon="receipt-percent"
            color="amber"
        />
        <x-ui.stat-card
            label="Total Gross"
            :value="'£' . number_format($this->summary['totalGross'], 2)"
            icon="calculator"
            color="emerald"
        />
    </div>

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900 flex flex-col gap-3">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <flux:input
                wire:model.live.debounce.300ms="search"
                autocomplete="off"
                icon="magnifying-glass"
                :placeholder="__('Search by supplier or invoice number…')"
                clearable
                class="flex-1 max-w-sm"
            />

            <div class="ml-auto flex items-center gap-2">
                <x-ui.per-page-select />

                <flux:button icon="envelope" x-on:click="$flux.modal('send-report').show()">{{ __('Send Report') }}</flux:button>

                <flux:button icon="printer" x-on:click="window.printPdfDocument('{{ $this->printUrl() }}')">{{ __('Print') }}</flux:button>

                <flux:dropdown>
                    <flux:button icon="arrow-down-tray" icon-trailing="chevron-down">{{ __('Export') }}</flux:button>
                    <flux:menu>
                        <flux:menu.item icon="table-cells" :href="$this->exportUrl('csv')">CSV</flux:menu.item>
                        <flux:menu.item icon="table-cells" :href="$this->exportUrl('xlsx')">Excel</flux:menu.item>
                        <flux:menu.item icon="document-text" :href="$this->exportUrl('pdf')">PDF</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="w-56">
                <livewire:pages::ui.typeahead
                    :key="'typeahead-supplier-purchasing-supplier'"
                    wire:model.live="supplierId"
                    model="App\Models\Supplier"
                    column="company_name"
                    :search-columns="['company_name', 'reference']"
                    label-accessor="typeahead_label"
                    :min-chars="2"
                    label="Supplier"
                    placeholder="Search supplier…"
                    :selected-label="$this->selectedSupplierLabel"
                />
            </div>

            <div class="flex flex-col">
                <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('Period') }}</label>
                <flux:select wire:model.live="period" size="sm" class="!w-40">
                    <flux:select.option value="today">{{ __('Today') }}</flux:select.option>
                    <flux:select.option value="yesterday">{{ __('Yesterday') }}</flux:select.option>
                    <flux:select.option value="this_month">{{ __('This Month') }}</flux:select.option>
                    <flux:select.option value="last_month">{{ __('Last Month') }}</flux:select.option>
                    <flux:select.option value="last_3_months">{{ __('Last 3 Months') }}</flux:select.option>
                    <flux:select.option value="last_6_months">{{ __('Last 6 Months') }}</flux:select.option>
                    <flux:select.option value="this_year">{{ __('This Year') }}</flux:select.option>
                    <flux:select.option value="custom">{{ __('Custom') }}</flux:select.option>
                </flux:select>
            </div>

            @if($period === 'custom')
                <x-ui.range-filters
                    :date-from="$dateFrom"
                    :date-to="$dateTo"
                    :amount-min="$amountMin"
                    :amount-max="$amountMax"
                />
            @endif

            <div class="flex flex-col">
                <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('Paid Status') }}</label>
                <flux:select wire:model.live="paidStatus" size="sm" class="!w-32">
                    <flux:select.option value="">{{ __('All') }}</flux:select.option>
                    <flux:select.option value="paid">{{ __('Paid') }}</flux:select.option>
                    <flux:select.option value="partial">{{ __('Partial') }}</flux:select.option>
                    <flux:select.option value="unpaid">{{ __('Unpaid') }}</flux:select.option>
                </flux:select>
            </div>
        </div>
    </div>

    {{-- Table card --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        @if($this->suppliers->isEmpty())
            <x-ui.empty-state
                icon="building-office"
                title="No supplier invoices found"
                :description="$this->hasFilters || $search ? 'Try adjusting your search or filters.' : 'No posted supplier invoices in this period.'"
            />
        @else
            @php
                $columns = [
                    'company_name' => ['label' => 'Supplier Name', 'align' => 'text-left'],
                    'invoice_date' => ['label' => 'Date', 'align' => 'text-left'],
                    'supplier_invoice_no' => ['label' => 'Supplier Invoice No', 'align' => 'text-left'],
                    '__invoice_ref' => ['label' => 'Invoice', 'align' => 'text-left'],
                    'net' => ['label' => 'Net', 'align' => 'text-right'],
                    'vat' => ['label' => 'VAT', 'align' => 'text-right'],
                    'gross' => ['label' => 'Gross', 'align' => 'text-right'],
                    '__debit_note' => ['label' => 'Debit Note', 'align' => 'text-left'],
                    '__deductions' => ['label' => 'Deductions', 'align' => 'text-right'],
                    '__net_payable' => ['label' => 'Net Payable', 'align' => 'text-right'],
                    '__status' => ['label' => 'Status', 'align' => 'text-center'],
                ];
                $sortableColumns = ['company_name', 'invoice_date', 'supplier_invoice_no', 'net', 'vat', 'gross'];
            @endphp
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-amber-600/20 bg-amber-400 dark:bg-amber-500">
                        <tr>
                            @foreach($columns as $key => $col)
                                @php $sortable = in_array($key, $sortableColumns, true); @endphp
                                <th class="px-2 py-1 {{ $col['align'] }} text-xs font-bold uppercase tracking-wider text-zinc-900">
                                    @if($sortable)
                                        <button
                                            type="button"
                                            wire:click="sortByColumn('{{ $key }}')"
                                            class="inline-flex items-center gap-0.5 hover:text-zinc-700"
                                        >
                                            {{ $col['label'] }}
                                            @if($this->sortBy === $key)
                                                <flux:icon :icon="$this->sortDirection === 'asc' ? 'chevron-up' : 'chevron-down'" class="size-3" />
                                            @endif
                                        </button>
                                    @else
                                        {{ $col['label'] }}
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    @foreach($this->suppliers as $supplier)
                        @php
                            $supplierNet = $supplier->supplierInvoices->sum(fn ($invoice) => $invoice->netTotal);
                            $supplierVat = $supplier->supplierInvoices->sum(fn ($invoice) => $invoice->vatTotal);
                            $supplierGross = $supplier->supplierInvoices->sum(fn ($invoice) => $invoice->grossTotal);
                            $supplierDeductions = $supplier->supplierInvoices->sum(fn ($invoice) => $this->deductionsOf($invoice));
                            $supplierNetPayable = $supplier->supplierInvoices->sum(fn ($invoice) => $this->netPayableOf($invoice));
                            $invoiceCount = $supplier->supplierInvoices->count();
                        @endphp
                        <tbody x-data="{ open: true }" wire:key="supplier-group-{{ $supplier->id }}">
                                <tr class="bg-zinc-100 dark:bg-zinc-800/60">
                                    <td colspan="11" class="px-2 py-1.5">
                                        <div class="flex items-center gap-2">
                                            <button
                                                type="button"
                                                x-on:click="open = !open"
                                                class="flex items-center justify-center rounded p-0.5 text-zinc-500 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-white/10"
                                            >
                                                <flux:icon icon="chevron-down" class="size-3.5 transition-transform" x-bind:class="{ '-rotate-90': !open }" />
                                            </button>
                                            <flux:icon icon="building-office-2" class="size-4 text-zinc-500 dark:text-zinc-400" />
                                            <a href="{{ route('suppliers.show', $supplier) }}" wire:navigate class="font-semibold text-zinc-900 hover:underline dark:text-white">
                                                {{ $supplier->company_name }}
                                            </a>
                                            <span class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $supplier->reference }}</span>
                                            <span class="ml-auto text-xs font-medium text-zinc-500 dark:text-zinc-400">
                                                {{ $invoiceCount }} {{ Str::plural('invoice', $invoiceCount) }}
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                @foreach($supplier->supplierInvoices as $invoice)
                                    @php
                                        $deductions = $this->deductionsOf($invoice);
                                        $debitRefs = $this->debitNoteRefsOf($invoice);
                                        $netPayable = $this->netPayableOf($invoice);
                                    @endphp
                                    <tr
                                        x-show="open"
                                        wire:key="invoice-{{ $invoice->id }}"
                                        class="{{ $loop->even ? 'bg-white dark:bg-zinc-900' : 'bg-zinc-50 dark:bg-white/[0.03]' }} border-b border-zinc-100 transition-colors hover:bg-indigo-50/40 dark:border-white/[0.04] dark:hover:bg-indigo-500/5"
                                    >
                                        <td class="px-2 py-1"></td>
                                        <td class="px-2 py-1 text-zinc-500 dark:text-zinc-400">{{ $invoice->invoice_date?->format('d M Y') }}</td>
                                        <td class="px-2 py-1">
                                            <a href="{{ route('supplier-invoices.show', $invoice) }}" wire:navigate class="font-mono text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                                {{ $invoice->supplier_invoice_no }}
                                            </a>
                                        </td>
                                        <td class="px-2 py-1 text-zinc-600 dark:text-zinc-400">{{ $invoice->supplier_ref_invoice_no }}</td>
                                        <td class="px-2 py-1 text-right font-mono tabular-nums text-zinc-900 dark:text-white">£{{ number_format($invoice->netTotal, 2) }}</td>
                                        <td class="px-2 py-1 text-right font-mono tabular-nums text-zinc-900 dark:text-white">£{{ number_format($invoice->vatTotal, 2) }}</td>
                                        <td class="px-2 py-1 text-right font-mono tabular-nums font-medium text-zinc-900 dark:text-white">£{{ number_format($invoice->grossTotal, 2) }}</td>
                                        <td class="px-2 py-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $debitRefs !== '' ? $debitRefs : '—' }}</td>
                                        <td class="px-2 py-1 text-right font-mono tabular-nums {{ $deductions > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-400 dark:text-zinc-600' }}">{{ $deductions > 0 ? '−£'.number_format($deductions, 2) : '—' }}</td>
                                        <td class="px-2 py-1 text-right font-mono tabular-nums font-medium text-zinc-900 dark:text-white">£{{ number_format($netPayable, 2) }}</td>
                                        <td class="px-2 py-1 text-center">
                                            <x-ui.payment-status-badge :status="$invoice->paymentStatus()" />
                                        </td>
                                    </tr>
                                @endforeach
                                <tr x-show="open" wire:key="supplier-{{ $supplier->id }}-total" class="border-b-2 border-zinc-200 bg-zinc-50/70 dark:border-white/10 dark:bg-white/[0.02]">
                                    <td class="px-2 py-1" colspan="4"></td>
                                    <td class="px-2 py-1 text-right font-mono tabular-nums font-bold text-emerald-600 dark:text-emerald-400">£{{ number_format($supplierNet, 2) }}</td>
                                    <td class="px-2 py-1 text-right font-mono tabular-nums font-bold text-emerald-600 dark:text-emerald-400">£{{ number_format($supplierVat, 2) }}</td>
                                    <td class="px-2 py-1 text-right font-mono tabular-nums font-bold text-emerald-600 dark:text-emerald-400">£{{ number_format($supplierGross, 2) }}</td>
                                    <td class="px-2 py-1"></td>
                                    <td class="px-2 py-1 text-right font-mono tabular-nums font-bold {{ $supplierDeductions > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-400 dark:text-zinc-600' }}">{{ $supplierDeductions > 0 ? '−£'.number_format($supplierDeductions, 2) : '—' }}</td>
                                    <td class="px-2 py-1 text-right font-mono tabular-nums font-bold text-emerald-600 dark:text-emerald-400">£{{ number_format($supplierNetPayable, 2) }}</td>
                                    <td class="px-2 py-1"></td>
                                </tr>
                        </tbody>
                    @endforeach
                    <tfoot>
                        <tr class="border-t-2 border-indigo-200 bg-indigo-50/60 dark:border-indigo-500/30 dark:bg-indigo-500/10">
                            <td class="px-2 py-2 font-semibold text-zinc-900 dark:text-white" colspan="4">{{ __('Grand Total for Selected Period') }}</td>
                            <td class="px-2 py-2 text-right font-mono tabular-nums font-bold text-zinc-900 dark:text-white">£{{ number_format($this->summary['totalNet'], 2) }}</td>
                            <td class="px-2 py-2 text-right font-mono tabular-nums font-bold text-zinc-900 dark:text-white">£{{ number_format($this->summary['totalVat'], 2) }}</td>
                            <td class="px-2 py-2 text-right font-mono tabular-nums font-bold text-zinc-900 dark:text-white">£{{ number_format($this->summary['totalGross'], 2) }}</td>
                            <td class="px-2 py-2"></td>
                            <td class="px-2 py-2 text-right font-mono tabular-nums font-bold {{ $this->summary['totalDeductions'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->summary['totalDeductions'] > 0 ? '−£'.number_format($this->summary['totalDeductions'], 2) : '£0.00' }}</td>
                            <td class="px-2 py-2 text-right font-mono tabular-nums font-bold text-zinc-900 dark:text-white">£{{ number_format($this->summary['totalNetPayable'], 2) }}</td>
                            <td class="px-2 py-2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Footer: pagination --}}
            <flux:pagination :paginator="$this->suppliers" class="px-6" />
        @endif
    </div>

    {{-- Send Report modal --}}
    <flux:modal name="send-report" focusable class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Send Report') }}</flux:heading>
                <flux:subheading>
                    {{ __('Send the current filtered report to the recipients below.') }}
                </flux:subheading>
            </div>

            <form wire:submit="sendReportEmail" class="space-y-4">
                <div x-data="emailTagInput($wire, @js($reportEmails), 'reportEmails')" wire:ignore>
                    <flux:label>{{ __('Recipients') }} <span class="text-rose-500">*</span></flux:label>
                    <div
                        class="mt-1 flex min-h-[38px] flex-wrap gap-1.5 rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500 dark:border-white/15 dark:bg-zinc-800"
                        x-on:click="$refs.tagInput.focus()"
                    >
                        <template x-for="(tag, i) in tags" :key="i">
                            <span class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-500/20 dark:text-indigo-300 dark:ring-indigo-500/30">
                                <span x-text="tag"></span>
                                <button type="button" x-on:click.stop="removeTag(i)" class="flex items-center text-indigo-400 hover:text-indigo-600 dark:hover:text-indigo-200">
                                    <svg class="size-3" viewBox="0 0 12 12" fill="none"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                </button>
                            </span>
                        </template>
                        <input
                            x-ref="tagInput"
                            x-model="input"
                            x-on:keydown="onKeydown($event)"
                            x-on:blur="addTag()"
                            x-on:paste="onPaste($event)"
                            type="text"
                            placeholder="Type email and press Enter…"
                            class="min-w-[160px] flex-1 border-0 bg-transparent p-0 text-sm text-zinc-900 placeholder-zinc-400 outline-none focus:ring-0 dark:text-white"
                        />
                    </div>
                    <p x-show="error" x-text="error" class="mt-1 text-xs text-rose-500"></p>
                </div>
                <flux:error name="reportEmails" />
                <flux:error name="reportEmails.*" />

                <div>
                    <flux:label>{{ __('Attach as') }} <span class="text-rose-500">*</span></flux:label>
                    <div class="mt-1 flex flex-wrap gap-4">
                        <flux:checkbox wire:model="reportFormats" value="pdf" label="PDF" />
                        <flux:checkbox wire:model="reportFormats" value="xlsx" label="Excel" />
                        <flux:checkbox wire:model="reportFormats" value="csv" label="CSV" />
                    </div>
                    <flux:error name="reportFormats" />
                </div>

                <div>
                    <flux:label>{{ __('Additional Notes') }}</flux:label>
                    <flux:textarea
                        wire:model="reportNotes"
                        :placeholder="__('Optional message to include in the email…')"
                        rows="3"
                    />
                    <flux:error name="reportNotes" />
                </div>

                <div class="flex justify-end gap-3">
                    <flux:modal.close>
                        <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button
                        variant="primary"
                        icon="envelope"
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="sendReportEmail"
                    >
                        <span wire:loading.remove wire:target="sendReportEmail">{{ __('Send Report') }}</span>
                        <span wire:loading wire:target="sendReportEmail">{{ __('Sending…') }}</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

</div>
