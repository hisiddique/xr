<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\SupplierPayout;
use App\Traits\WithPerPage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Supplier Payouts')] class extends Component {
    use WithPagination;
    use WithSorting;
    use WithPerPage;

    protected array $sortable = ['reference', 'payout_date', 'amount'];

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

    public function delete(int $id): void
    {
        SupplierPayout::findOrFail($id)->delete();
        $this->deletingId = null;
        Flux::toast('Payout deleted.');
        $this->dispatch('$refresh');
    }

    #[Computed]
    public function payouts()
    {
        return SupplierPayout::with(['supplier', 'allocations'])
            ->when($this->search, fn ($q) => $q->where(fn ($q2) =>
                $q2->where('reference', 'like', '%'.$this->search.'%')
                   ->orWhereHas('supplier', fn ($s) => $s->where('company_name', 'like', '%'.$this->search.'%'))
            ))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('payout_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('payout_date', '<=', $this->dateTo))
            ->when($this->amountMin !== '', fn ($q) => $q->where('amount', '>=', $this->amountMin))
            ->when($this->amountMax !== '', fn ($q) => $q->where('amount', '<=', $this->amountMax))
            ->tap(fn ($q) => $this->applySort($q))
            ->when($this->sortColumn === '', fn ($q) => $q->orderByDesc('payout_date'))
            ->paginate($this->perPage);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Supplier Payouts"
        subtitle="Record outbound supplier payments."
    >
        <x-slot:action>
            <flux:button variant="primary" icon="arrow-right" :href="route('supplier-debit-notes.create')" wire:navigate>
                Issue Debit Note &amp; Pay
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
                    :placeholder="__('Search by reference or supplier…')"
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

        @if($this->payouts->isEmpty())
            <x-ui.empty-state
                icon="banknotes"
                title="No supplier payouts found"
                :description="($search || $dateFrom || $dateTo || $amountMin || $amountMax) ? 'Try adjusting your search or filters.' : 'Payouts are created when issuing a supplier debit note.'"
            >
                @unless($search || $dateFrom || $dateTo || $amountMin || $amountMax)
                    <x-slot:action>
                        <flux:button variant="primary" :href="route('supplier-debit-notes.create')" wire:navigate>
                            Issue Debit Note &amp; Pay
                        </flux:button>
                    </x-slot:action>
                @endunless
            </x-ui.empty-state>
        @else
            <div x-data="zoneNav('table')" data-zone="table" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30">
                <table class="w-full text-sm">
                    <thead class="sticky top-14 lg:top-16 z-10 bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <x-ui.sortable-header column="reference" :state="$this->sortStateFor('reference')">Reference</x-ui.sortable-header>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Supplier</th>
                            <x-ui.sortable-header column="amount" align="right" :state="$this->sortStateFor('amount')">Amount</x-ui.sortable-header>
                            <th class="px-4 py-1 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Allocated</th>
                            <th class="px-4 py-1 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Unallocated</th>
                            <x-ui.sortable-header column="payout_date" :state="$this->sortStateFor('payout_date')">Date</x-ui.sortable-header>
                            <th class="px-4 py-1"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->payouts as $payout)
                            @php
                                $allocated = $payout->allocations->sum('allocated_amount');
                                $unallocated = max(0, $payout->amount - $allocated);
                            @endphp
                            <tr
                                data-row-index="{{ $loop->index }}"
                                data-view-url="{{ route('supplier-payouts.show', $payout) }}"
                                data-edit-url="{{ route('supplier-payouts.edit', $payout) }}"
                                @class([
                                    'transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5',
                                    'sticky bottom-0 z-10 bg-white dark:bg-zinc-900 shadow-[0_-1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_-1px_0_0_theme(--color-white/0.06)]' => $loop->last,
                                    'sticky top-[5.75rem] lg:top-[6.25rem] z-10 bg-white dark:bg-zinc-900 shadow-[0_1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_1px_0_0_theme(--color-white/0.06)]' => $loop->first,
                                ])
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2">
                                    <a href="{{ route('supplier-payouts.show', $payout) }}" wire:navigate class="font-mono text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                                        {{ $payout->reference }}
                                    </a>
                                </td>
                                <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">
                                    {{ $payout->supplier?->company_name ?? '—' }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-semibold text-zinc-900 dark:text-white">
                                    £{{ number_format($payout->amount, 2) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums {{ $allocated >= $payout->amount ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-600 dark:text-zinc-300' }}">
                                    £{{ number_format($allocated, 2) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-medium {{ $unallocated > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    £{{ number_format($unallocated, 2) }}
                                </td>
                                <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">
                                    {{ $payout->payout_date->format('d M Y') }}
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('supplier-payouts.show', $payout)" wire:navigate data-row-action="view" />
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('supplier-payouts.edit', $payout)" wire:navigate data-row-action="edit" />
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="trash"
                                            wire:click="$set('deletingId', {{ $payout->id }}); $flux.modal('delete-payout').show()"
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
            <flux:pagination :paginator="$this->payouts" class="px-6" />
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

    {{-- Delete modal --}}
    <flux:modal name="delete-payout" class="max-w-sm">
        <div class="flex flex-col gap-4 p-1">
            <div>
                <flux:heading size="lg">Delete payout?</flux:heading>
                <flux:subheading class="mt-1">
                    Deleting this payout will also remove all its allocation records. This cannot be undone.
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button x-on:click="$flux.modal('delete-payout').close()">Cancel</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="delete({{ $deletingId ?? 0 }})"
                    wire:loading.attr="disabled"
                >
                    Delete
                </flux:button>
            </div>
        </div>
    </flux:modal>

</div>
