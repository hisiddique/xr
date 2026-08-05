<?php

use App\Models\Customer;
use App\Models\Document;
use App\Services\CustomerOutstandingReportService;
use App\Traits\WithPerPage;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customer Outstanding Payments')] class extends Component
{
    use WithPagination;
    use WithPerPage;

    #[Url]
    public string $search = '';

    #[Url(as: 'customer', except: '')]
    public string $customerId = '';

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'min', except: '')]
    public string $amountMin = '';

    #[Url(as: 'max', except: '')]
    public string $amountMax = '';

    #[Url(as: 'osmin', except: '')]
    public string $osMin = '';

    #[Url(as: 'osmax', except: '')]
    public string $osMax = '';

    public int $perPage = 100;

    /** @var array<int, string> */
    public array $reportEmails = [];

    /** @var array<int, string> */
    public array $reportFormats = ['pdf'];

    public string $reportNotes = '';

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

    public function updatedAmountMin(): void
    {
        $this->resetPage();
    }

    public function updatedAmountMax(): void
    {
        $this->resetPage();
    }

    public function updatedOsMin(): void
    {
        $this->resetPage();
    }

    public function updatedOsMax(): void
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
        $this->amountMin = '';
        $this->amountMax = '';
        $this->osMin = '';
        $this->osMax = '';
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
            'amountMin' => $this->amountMin,
            'amountMax' => $this->amountMax,
            'osMin' => $this->osMin,
            'osMax' => $this->osMax,
        ];
    }

    public function exportUrl(string $format): string
    {
        $params = array_filter($this->filters(), fn ($value) => $value !== null && $value !== '');

        return route('reports.customer-outstanding-payments.export', array_merge(['format' => $format], $params));
    }

    #[Computed]
    public function customers()
    {
        return app(CustomerOutstandingReportService::class)
            ->customersQuery($this->filters())
            ->paginate($this->perPage);
    }

    public function outstanding(Document $invoice): float
    {
        return app(CustomerOutstandingReportService::class)->outstandingAmount($invoice);
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
        return $this->customerId !== '' || $this->dateFrom !== '' || $this->dateTo !== ''
            || $this->amountMin !== '' || $this->amountMax !== '' || $this->osMin !== '' || $this->osMax !== '';
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

        try {
            app(CustomerOutstandingReportService::class)->sendReport(
                $this->filters(),
                $this->reportEmails,
                $this->reportFormats,
                $this->reportNotes ?: null,
            );

            Flux::modal('send-report')->close();
            Flux::toast(variant: 'success', text: __('Report sent to :recipient.', ['recipient' => $this->reportEmails[0]]));
        } catch (\Throwable $e) {
            $this->addError('reportEmails', $e->getMessage());
        }
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Customer Outstanding Payments"
        subtitle="Outstanding invoice balances grouped by customer."
    />

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900 flex flex-col gap-3">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <flux:input
                wire:model.live.debounce.300ms="search"
                autocomplete="off"
                icon="magnifying-glass"
                :placeholder="__('Search by customer or invoice number…')"
                clearable
                class="flex-1 max-w-sm"
            />

            <div class="ml-auto flex items-center gap-2">
                <x-ui.per-page-select />

                <flux:button icon="envelope" x-on:click="$flux.modal('send-report').show()">{{ __('Send Report') }}</flux:button>

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
                    :key="'typeahead-outstanding-customer'"
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

            <x-ui.range-filters
                :date-from="$dateFrom"
                :date-to="$dateTo"
                :amount-min="$amountMin"
                :amount-max="$amountMax"
                :extra-has-value="$osMin !== '' || $osMax !== ''"
            >
                <x-slot:extra>
                    <div class="flex flex-col">
                        <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('O/S Min £') }}</label>
                        <flux:input wire:model.live.debounce.400ms="osMin" type="number" step="0.01" min="0" size="sm" placeholder="0.00" class="!w-28" />
                    </div>
                    <div class="flex flex-col">
                        <label class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('O/S Max £') }}</label>
                        <flux:input wire:model.live.debounce.400ms="osMax" type="number" step="0.01" min="0" size="sm" placeholder="0.00" class="!w-28" />
                    </div>
                </x-slot:extra>
            </x-ui.range-filters>
        </div>
    </div>

    {{-- Table card --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        @if($this->customers->isEmpty())
            <x-ui.empty-state
                icon="banknotes"
                title="No outstanding invoices"
                :description="$this->hasFilters || $search ? 'Try adjusting your search or filters.' : 'All invoices are fully settled.'"
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-amber-600/20 bg-amber-400 dark:bg-amber-500">
                        <tr>
                            <th class="px-2 py-1 text-left text-xs font-bold uppercase tracking-wider text-zinc-900">Customer Name</th>
                            <th class="px-2 py-1 text-left text-xs font-bold uppercase tracking-wider text-zinc-900">Date</th>
                            <th class="px-2 py-1 text-left text-xs font-bold uppercase tracking-wider text-zinc-900">Invoice</th>
                            <th class="px-2 py-1 text-right text-xs font-bold uppercase tracking-wider text-zinc-900">Total</th>
                            <th class="px-2 py-1 text-right text-xs font-bold uppercase tracking-wider text-zinc-900">O/S</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->customers as $customer)
                            @php
                                $customerTotal = $customer->invoices->sum('total_value');
                                $customerOutstanding = $customer->invoices->sum(fn ($invoice) => $this->outstanding($invoice));
                                $bandClass = $loop->even ? 'bg-white dark:bg-zinc-900' : 'bg-zinc-50 dark:bg-white/[0.03]';
                            @endphp
                            @foreach($customer->invoices as $invoice)
                                @php
                                    $outstanding = $this->outstanding($invoice);
                                @endphp
                                <tr class="{{ $bandClass }} border-b border-zinc-100 transition-colors hover:bg-indigo-50/40 dark:border-white/[0.04] dark:hover:bg-indigo-500/5">
                                    @if($loop->first)
                                        <td rowspan="{{ $customer->invoices->count() + 1 }}" class="border-r border-zinc-200 px-2 py-1 align-top font-semibold text-zinc-900 dark:border-white/10 dark:text-white">
                                            <a href="{{ route('customers.show', $customer) }}" wire:navigate class="hover:underline">
                                                {{ $customer->company_name }}
                                            </a>
                                            <span class="ml-1 font-mono text-xs font-normal text-zinc-400 dark:text-zinc-500">{{ $customer->reference }}</span>
                                        </td>
                                    @endif
                                    <td class="px-2 py-1 text-zinc-500 dark:text-zinc-400">{{ $invoice->doc_date?->format('d M Y') }}</td>
                                    <td class="px-2 py-1">
                                        <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="font-mono text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                            {{ $invoice->doc_number }}
                                        </a>
                                    </td>
                                    <td class="px-2 py-1 text-right font-mono tabular-nums text-zinc-900 dark:text-white">£{{ number_format($invoice->total_value, 2) }}</td>
                                    <td class="px-2 py-1 text-right font-mono tabular-nums font-medium text-amber-600 dark:text-amber-400">£{{ number_format($outstanding, 2) }}</td>
                                </tr>
                            @endforeach
                            <tr class="{{ $bandClass }} border-b-2 border-zinc-200 dark:border-white/10">
                                <td class="px-2 py-1"></td>
                                <td class="px-2 py-1"></td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums font-bold text-emerald-600 dark:text-emerald-400">£{{ number_format($customerTotal, 2) }}</td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums font-bold text-emerald-600 dark:text-emerald-400">£{{ number_format($customerOutstanding, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Footer: pagination --}}
            <flux:pagination :paginator="$this->customers" class="px-6" />
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
