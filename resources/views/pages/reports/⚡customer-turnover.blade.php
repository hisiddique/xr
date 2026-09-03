<?php

use App\ExportJobStatus;
use App\Jobs\SendCustomerTurnoverReportJob;
use App\Models\Customer;
use App\Models\ExportJob;
use App\Services\CustomerTurnoverReportService;
use App\Traits\WithPerPage;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customer Turnover')] class extends Component
{
    use WithPagination;
    use WithPerPage;

    #[Url]
    public string $search = '';

    #[Url(as: 'customer', except: '')]
    public string $customerId = '';

    #[Url(as: 'preset', except: 'this_month')]
    public string $preset = 'this_month';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'min', except: '')]
    public string $totalMin = '';

    #[Url(as: 'max', except: '')]
    public string $totalMax = '';

    #[Url(as: 'os', except: false)]
    public bool $includeOutstanding = false;

    #[Url(as: 'sort', except: 'total')]
    public string $sortColumn = 'total';

    #[Url(as: 'dir', except: 'desc')]
    public string $sortDirection = 'desc';

    public int $perPage = 100;

    /** @var array<int, string> */
    protected array $sortable = ['company_name', 'invoice_count', 'total', 'outstanding'];

    /** @var array<int, string> */
    public array $reportEmails = [];

    /** @var array<int, string> */
    public array $reportFormats = ['pdf'];

    public string $reportNotes = '';

    public function mount(): void
    {
        if ($this->preset !== 'custom') {
            $this->applyPreset();
        }
    }

    protected function applyPreset(): void
    {
        [$this->dateFrom, $this->dateTo] = match ($this->preset) {
            'today' => [Carbon::today()->toDateString(), Carbon::today()->toDateString()],
            'yesterday' => [Carbon::yesterday()->toDateString(), Carbon::yesterday()->toDateString()],
            'this_week' => [Carbon::now()->startOfWeek()->toDateString(), Carbon::now()->endOfWeek()->toDateString()],
            'last_week' => [Carbon::now()->subWeek()->startOfWeek()->toDateString(), Carbon::now()->subWeek()->endOfWeek()->toDateString()],
            'last_two_weeks' => [Carbon::now()->subWeeks(2)->startOfWeek()->toDateString(), Carbon::now()->endOfWeek()->toDateString()],
            'this_month' => [Carbon::now()->startOfMonth()->toDateString(), Carbon::now()->endOfMonth()->toDateString()],
            'last_month' => [Carbon::now()->subMonth()->startOfMonth()->toDateString(), Carbon::now()->subMonth()->endOfMonth()->toDateString()],
            'three_months_ago' => [Carbon::now()->subMonths(3)->startOfMonth()->toDateString(), Carbon::now()->subMonths(3)->endOfMonth()->toDateString()],
            'six_months_ago' => [Carbon::now()->subMonths(6)->startOfMonth()->toDateString(), Carbon::now()->subMonths(6)->endOfMonth()->toDateString()],
            'this_year' => [Carbon::now()->startOfYear()->toDateString(), Carbon::now()->endOfYear()->toDateString()],
            'last_year' => [Carbon::now()->subYear()->startOfYear()->toDateString(), Carbon::now()->subYear()->endOfYear()->toDateString()],
            'custom' => [$this->dateFrom, $this->dateTo],
            default => ['', ''],
        };
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, $this->sortable, true)) {
            return;
        }

        if ($this->sortColumn !== $column) {
            $this->sortColumn = $column;
            $this->sortDirection = 'desc';
        } elseif ($this->sortDirection === 'desc') {
            $this->sortDirection = 'asc';
        } else {
            $this->sortColumn = '';
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function sortStateFor(string $column): ?string
    {
        if ($this->sortColumn !== $column) {
            return null;
        }

        return $this->sortDirection === 'desc' ? 'desc' : 'asc';
    }

    public function updatedPreset(): void
    {
        if ($this->preset !== 'custom') {
            $this->applyPreset();
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCustomerId(): void
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

    public function updatedTotalMin(): void
    {
        $this->resetPage();
    }

    public function updatedTotalMax(): void
    {
        $this->resetPage();
    }

    public function updatedIncludeOutstanding(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->customerId = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->totalMin = '';
        $this->totalMax = '';
        $this->preset = 'this_month';
        $this->applyPreset();
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function filters(): array
    {
        return [
            'search' => $this->search,
            'customerId' => $this->customerId !== '' ? (int) $this->customerId : null,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'totalMin' => $this->totalMin,
            'totalMax' => $this->totalMax,
            'includeOutstanding' => $this->includeOutstanding,
            'sortColumn' => in_array($this->sortColumn, $this->sortable, true) ? $this->sortColumn : 'total',
            'sortDirection' => $this->sortColumn === '' ? 'desc' : $this->sortDirection,
        ];
    }

    #[Computed]
    public function rows()
    {
        return app(CustomerTurnoverReportService::class)
            ->customersQuery($this->filters())
            ->paginate($this->perPage);
    }

    #[Computed]
    public function selectedCustomerLabel(): string
    {
        if ($this->customerId === '' || ! is_numeric($this->customerId)) {
            return '';
        }

        return Customer::find((int) $this->customerId)?->typeahead_label ?? '';
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->customerId !== '' || $this->totalMin !== '' || $this->totalMax !== '' || $this->preset !== 'this_month';
    }

    /**
     * @return array{invoice_count: int, total: float, outstanding: float}
     */
    #[Computed]
    public function pageTotals(): array
    {
        $items = collect($this->rows->items());

        return [
            'invoice_count' => (int) $items->sum('invoice_count'),
            'total' => (float) $items->sum('total'),
            'outstanding' => (float) $items->sum(fn ($row) => $row->outstanding ?? 0),
        ];
    }

    public function exportUrl(string $format): string
    {
        $params = array_filter([
            'search' => $this->search,
            'customerId' => $this->customerId,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'totalMin' => $this->totalMin,
            'totalMax' => $this->totalMax,
            'sortColumn' => $this->sortColumn,
            'sortDirection' => $this->sortDirection,
        ], fn ($v) => $v !== null && $v !== '');

        $params['includeOutstanding'] = $this->includeOutstanding ? 1 : 0;

        return route('reports.customer-turnover.export', array_merge(['format' => $format], $params));
    }

    public function printUrl(): string
    {
        $params = array_filter([
            'search' => $this->search,
            'customerId' => $this->customerId,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'totalMin' => $this->totalMin,
            'totalMax' => $this->totalMax,
            'sortColumn' => $this->sortColumn,
            'sortDirection' => $this->sortDirection,
        ], fn ($v) => $v !== null && $v !== '');

        $params['includeOutstanding'] = $this->includeOutstanding ? 1 : 0;
        $params['inline'] = 1;

        return route('reports.customer-turnover.export', array_merge(['format' => 'pdf'], $params));
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
            'type' => 'customer_turnover',
            'format' => 'email',
            'filters' => $filters,
            'rows_total' => app(CustomerTurnoverReportService::class)->customersQuery($filters)->count(),
            'created_by' => auth()->id(),
        ]);

        SendCustomerTurnoverReportJob::dispatch(
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
        title="Customer Turnover"
        subtitle="Invoiced turnover per customer for the selected period."
    />

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900 flex flex-col gap-3">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <flux:input
                wire:model.live.debounce.300ms="search"
                autocomplete="off"
                icon="magnifying-glass"
                :placeholder="__('Search by customer name or reference…')"
                clearable
                class="flex-1 max-w-sm"
            />

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <flux:select wire:model.live="preset" size="sm" class="!w-40">
                    <flux:select.option value="today">{{ __('Today') }}</flux:select.option>
                    <flux:select.option value="yesterday">{{ __('Yesterday') }}</flux:select.option>
                    <flux:select.option value="this_week">{{ __('This Week') }}</flux:select.option>
                    <flux:select.option value="last_week">{{ __('Last Week') }}</flux:select.option>
                    <flux:select.option value="last_two_weeks">{{ __('Last Two Weeks') }}</flux:select.option>
                    <flux:select.option value="this_month">{{ __('This Month') }}</flux:select.option>
                    <flux:select.option value="last_month">{{ __('Last Month') }}</flux:select.option>
                    <flux:select.option value="three_months_ago">{{ __('3 Months Ago') }}</flux:select.option>
                    <flux:select.option value="six_months_ago">{{ __('6 Months Ago') }}</flux:select.option>
                    <flux:select.option value="this_year">{{ __('This Year') }}</flux:select.option>
                    <flux:select.option value="last_year">{{ __('Last Year') }}</flux:select.option>
                    <flux:select.option value="custom">{{ __('Custom') }}</flux:select.option>
                </flux:select>

                <flux:switch wire:model.live="includeOutstanding" label="Include O/S" align="left" />

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
                    :key="'typeahead-turnover-customer'"
                    wire:model.live="customerId"
                    model="App\Models\Customer"
                    column="company_name"
                    :search-columns="['company_name', 'first_name', 'last_name', 'reference']"
                    label-accessor="typeahead_label"
                    :min-chars="2"
                    label="Customer"
                    placeholder="Search customer…"
                    :selected-label="$this->selectedCustomerLabel"
                />
            </div>

            @if($preset === 'custom')
                <div class="flex flex-col">
                    <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('From') }}</label>
                    <flux:input wire:model.live="dateFrom" type="date" size="sm" />
                </div>
                <div class="flex flex-col">
                    <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('To') }}</label>
                    <flux:input wire:model.live="dateTo" type="date" size="sm" />
                </div>
            @endif

            <div class="flex flex-col">
                <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('Total Min £') }}</label>
                <flux:input wire:model.live.debounce.400ms="totalMin" type="number" step="0.01" min="0" size="sm" placeholder="0.00" class="!w-28" />
            </div>
            <div class="flex flex-col">
                <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('Total Max £') }}</label>
                <flux:input wire:model.live.debounce.400ms="totalMax" type="number" step="0.01" min="0" size="sm" placeholder="0.00" class="!w-28" />
            </div>

            @if($this->hasFilters)
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">{{ __('Clear') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Table card --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        @if($this->rows->isEmpty())
            <x-ui.empty-state
                icon="chart-bar-square"
                title="No turnover in this period"
                :description="$this->hasFilters || $search ? 'Try adjusting your search or filters.' : 'No invoices were raised in this period.'"
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-indigo-700/20 bg-indigo-600 dark:bg-indigo-700">
                        <tr>
                            <x-ui.sortable-header column="company_name" tone="onDark" :state="$this->sortStateFor('company_name')">Customer</x-ui.sortable-header>
                            <x-ui.sortable-header column="invoice_count" tone="onDark" align="right" :state="$this->sortStateFor('invoice_count')">Invoices</x-ui.sortable-header>
                            <x-ui.sortable-header column="total" tone="onDark" align="right" :state="$this->sortStateFor('total')">Total</x-ui.sortable-header>
                            @if($includeOutstanding)
                                <x-ui.sortable-header column="outstanding" tone="onDark" align="right" :state="$this->sortStateFor('outstanding')">O/S</x-ui.sortable-header>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->rows as $row)
                            <tr wire:key="turnover-{{ $row->id }}" class="{{ $loop->even ? 'bg-zinc-50 dark:bg-white/[0.03]' : 'bg-white dark:bg-zinc-900' }} border-b border-zinc-100 transition-colors hover:bg-indigo-50/40 dark:border-white/[0.04] dark:hover:bg-indigo-500/5">
                                <td class="px-4 py-1 font-semibold text-zinc-900 dark:text-white">
                                    <a href="{{ route('customers.show', $row) }}" wire:navigate class="hover:underline">
                                        {{ $row->company_name }}
                                    </a>
                                    <span class="ml-1 font-mono text-xs font-normal text-zinc-400 dark:text-zinc-500">{{ $row->reference }}</span>
                                </td>
                                <td class="px-4 py-1 text-right font-mono tabular-nums text-zinc-900 dark:text-white">{{ number_format((int) $row->invoice_count) }}</td>
                                <td class="px-4 py-1 text-right font-mono tabular-nums font-medium text-zinc-900 dark:text-white">£{{ number_format($row->total, 2) }}</td>
                                @if($includeOutstanding)
                                    <td class="px-4 py-1 text-right font-mono tabular-nums font-medium text-amber-600 dark:text-amber-400">£{{ number_format($row->outstanding ?? 0, 2) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-indigo-200 bg-indigo-50/60 dark:border-indigo-500/30 dark:bg-indigo-500/10">
                            <td class="px-4 py-2 font-semibold text-zinc-900 dark:text-white">{{ __('Page total') }}</td>
                            <td class="px-4 py-2 text-right font-mono tabular-nums font-bold text-zinc-900 dark:text-white">{{ number_format($this->pageTotals['invoice_count']) }}</td>
                            <td class="px-4 py-2 text-right font-mono tabular-nums font-bold text-zinc-900 dark:text-white">£{{ number_format($this->pageTotals['total'], 2) }}</td>
                            @if($includeOutstanding)
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-bold text-amber-600 dark:text-amber-400">£{{ number_format($this->pageTotals['outstanding'], 2) }}</td>
                            @endif
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Footer: pagination --}}
            <flux:pagination :paginator="$this->rows" class="px-6" />
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
