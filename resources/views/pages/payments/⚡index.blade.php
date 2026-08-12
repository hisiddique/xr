<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Payment;
use App\Traits\WithPerPage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Payments')] class extends Component
{
    use WithPagination;
    use WithSorting;
    use WithPerPage;

    protected array $sortable = ['reference', 'payment_date', 'amount'];

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

    #[Computed]
    public function payments()
    {
        return Payment::query()
            ->with(['customer', 'paymentMethod'])
            ->withSum('allocations', 'allocated_amount')
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('reference', 'like', "%{$this->search}%")
                    ->orWhereHas('customer', fn ($q) => $q->where('company_name', 'like', "%{$this->search}%")->orWhere('reference', 'like', "%{$this->search}%"));
            }))
            ->when($this->dateFrom, fn ($q) => $q->where('payment_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('payment_date', '<=', $this->dateTo))
            ->when($this->amountMin, fn ($q) => $q->where('amount', '>=', $this->amountMin))
            ->when($this->amountMax, fn ($q) => $q->where('amount', '<=', $this->amountMax))
            ->tap(fn ($q) => $this->applySort($q))
            ->when($this->sortColumn === '', fn ($q) => $q->latest('payment_date')->orderBy('reference'))
            ->paginate($this->perPage);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Payments"
        subtitle="Track and manage all customer payments."
    />

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900 flex flex-col gap-3">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div x-data="zoneNav('search')" data-zone="search" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg flex-1 max-w-sm">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    data-search-input
                    autocomplete="off"
                    icon="magnifying-glass"
                    :placeholder="__('Search by reference or customer…')"
                    clearable
                    class="flex-1 max-w-sm"
                />
            </div>

            <div class="ml-auto flex items-center gap-2">
                <x-ui.per-page-select />

                <flux:button variant="primary" icon="plus" :href="route('payments.create')" wire:navigate>
                    {{ __('Record Payment') }}
                </flux:button>
            </div>
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

        @if($this->payments->isEmpty())
            <x-ui.empty-state
                icon="banknotes"
                title="No payments recorded"
                description="Record your first payment using the button above."
            />
        @else
            <div x-data="zoneNav('table')" data-zone="table" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30">
                <table class="w-full text-sm">
                    <thead class="sticky top-14 lg:top-16 z-10 bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <x-ui.sortable-header column="reference" :state="$this->sortStateFor('reference')">Reference</x-ui.sortable-header>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Customer</th>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Method</th>
                            <x-ui.sortable-header column="amount" align="right" :state="$this->sortStateFor('amount')">Amount</x-ui.sortable-header>
                            <th class="px-4 py-1 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Allocated</th>
                            <th class="px-4 py-1 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Unallocated</th>
                            <x-ui.sortable-header column="payment_date" :state="$this->sortStateFor('payment_date')">Date</x-ui.sortable-header>
                            <th class="px-4 py-1"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->payments as $payment)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                data-view-url="{{ route('payments.show', $payment) }}"
                                data-edit-url="{{ route('payments.edit', $payment) }}"
                                @class([
                                    'transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5',
                                    'sticky bottom-0 z-10 bg-white dark:bg-zinc-900 shadow-[0_-1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_-1px_0_0_theme(--color-white/0.06)]' => $loop->last,
                                    'sticky top-[5.75rem] lg:top-[6.25rem] z-10 bg-white dark:bg-zinc-900 shadow-[0_1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_1px_0_0_theme(--color-white/0.06)]' => $loop->first,
                                ])
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2">
                                    <a href="{{ route('payments.show', $payment) }}" wire:navigate class="inline-flex items-center rounded-md px-2 py-0.5 font-mono text-sm font-semibold text-indigo-700 hover:underline dark:text-indigo-300">
                                        {{ $payment->reference }}
                                    </a>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2.5">
                                        <x-ui.avatar :name="$payment->customer->company_name ?? $payment->customer->first_name" size="xs" />
                                        <span class="font-medium text-zinc-900 dark:text-white">
                                            {{ $payment->customer->company_name ?: trim(($payment->customer->first_name ?? '') . ' ' . ($payment->customer->last_name ?? '')) ?: '—' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-300">{{ $payment->paymentMethod?->name ?? $payment->source_type->label() }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-semibold text-zinc-900 dark:text-white">£{{ number_format($payment->amount, 2) }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-zinc-600 dark:text-zinc-300">£{{ number_format($payment->allocations_sum_allocated_amount ?? 0, 2) }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-medium {{ max(0, $payment->amount - ($payment->allocations_sum_allocated_amount ?? 0)) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    £{{ number_format(max(0, $payment->amount - ($payment->allocations_sum_allocated_amount ?? 0)), 2) }}
                                </td>
                                <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ $payment->payment_date->format('d M Y') }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('payments.show', $payment)" wire:navigate data-row-action="view" />
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('payments.edit', $payment)" wire:navigate data-row-action="edit" />
                                        @if($payment->receipt_path)
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-down-tray"
                                                :href="Storage::disk('public')->url($payment->receipt_path)"
                                                target="_blank"
                                                title="{{ __('Download receipt') }}"
                                                data-row-action="download-receipt"
                                            />
                                        @else
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-down-tray"
                                                disabled
                                                title="{{ __('No receipt uploaded') }}"
                                                class="opacity-30 cursor-not-allowed"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Footer: pagination --}}
            <flux:pagination :paginator="$this->payments" class="px-6" />
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

</div>
