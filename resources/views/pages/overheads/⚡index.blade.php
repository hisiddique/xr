<?php

use App\Models\ExpenseCategory;
use App\Models\Overhead;
use App\Livewire\Concerns\WithSorting;
use App\Traits\WithPerPage;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Overheads')] class extends Component {
    use WithSorting;
    use WithPerPage;
    use \Livewire\WithPagination;

    protected array $sortable = ['expense_date', 'amount', 'payment_method'];

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public ?int $filterCategory = null;

    public function updatedFilterCategory(): void
    {
        $this->resetPage();
    }

    public string $filterVat = '';

    public function updatedFilterVat(): void
    {
        $this->resetPage();
    }

    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'min', except: '')]
    public string $amountMin = '';

    #[Url(as: 'max', except: '')]
    public string $amountMax = '';

    public function updatedDateFrom(): void { $this->resetPage(); }

    public function updatedDateTo(): void { $this->resetPage(); }

    public function updatedAmountMin(): void { $this->resetPage(); }

    public function updatedAmountMax(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->amountMin = '';
        $this->amountMax = '';
        $this->filterCategory = null;
        $this->filterVat = '';
        $this->resetPage();
    }

    public int $perPage = 25;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public ?int $deletingId = null;

    public function deleteOverhead(): void
    {
        if (! $this->deletingId) {
            return;
        }

        Overhead::findOrFail($this->deletingId)->delete();
        $this->deletingId = null;
        Flux::modal('delete-overhead')->close();
        Flux::toast(variant: 'success', text: __('Overhead deleted.'));
    }

    #[Computed]
    public function categories()
    {
        return ExpenseCategory::orderBy('name')->get();
    }

    #[Computed]
    public function totalOverheads(): string
    {
        return '£' . number_format(Overhead::sum('amount'), 2);
    }

    #[Computed]
    public function vatReclaimable(): string
    {
        return '£' . number_format(Overhead::where('has_vat', true)->sum('amount'), 2);
    }

    #[Computed]
    public function topCategory(): string
    {
        $top = Overhead::selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->with('category')
            ->first();

        return $top ? $top->category->name : '—';
    }

    #[Computed]
    public function overheads()
    {
        $query = Overhead::with('category')
            ->when($this->search, fn ($q) => $q->whereHas('category', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
                ->orWhere('payment_method', 'like', "%{$this->search}%")
            )
            ->when($this->filterCategory, fn ($q) => $q->where('category_id', $this->filterCategory))
            ->when($this->filterVat !== '', fn ($q) => $q->where('has_vat', (bool) $this->filterVat))
            ->when($this->dateFrom, fn ($q) => $q->where('expense_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('expense_date', '<=', $this->dateTo))
            ->when($this->amountMin, fn ($q) => $q->where('amount', '>=', $this->amountMin))
            ->when($this->amountMax, fn ($q) => $q->where('amount', '<=', $this->amountMax));

        return $this->sortColumn === ''
            ? $this->applySort($query)->latest('expense_date')->paginate($this->perPage)
            : $this->applySort($query)->paginate($this->perPage);
    }

    #[Computed]
    public function deletingOverhead(): ?Overhead
    {
        return $this->deletingId
            ? Overhead::find($this->deletingId)
            : null;
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Overheads"
        subtitle="Track business expenses and overheads."
    >
        <x-slot:action>
            <flux:button variant="primary" icon="plus" :href="route('overheads.create')" wire:navigate>
                New Overhead
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card
            label="Total Overheads"
            :value="$this->totalOverheads"
            icon="banknotes"
            color="indigo"
        />
        <x-ui.stat-card
            label="VAT Reclaimable"
            :value="$this->vatReclaimable"
            icon="receipt-percent"
            color="emerald"
        />
        <x-ui.stat-card
            label="Top Expense Category"
            :value="$this->topCategory"
            icon="tag"
            color="amber"
        />
    </div>

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900 flex flex-col gap-3">
        <div class="flex items-center gap-3">
            <div x-data="zoneNav('search')" data-zone="search" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    data-search-input
                    autocomplete="off"
                    icon="magnifying-glass"
                    :placeholder="__('Search by category or payment method…')"
                    clearable
                />
            </div>
            <div class="ml-auto flex items-center gap-3">
                <flux:select wire:model.live="filterCategory" class="w-48">
                    <flux:select.option value="">All Categories</flux:select.option>
                    @foreach($this->categories as $cat)
                        <flux:select.option :value="$cat->id">{{ $cat->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="filterVat" class="w-36">
                    <flux:select.option value="">All VAT</flux:select.option>
                    <flux:select.option value="1">Inc. VAT</flux:select.option>
                    <flux:select.option value="0">Ex. VAT</flux:select.option>
                </flux:select>
                <x-ui.per-page-select />
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

        @if($this->overheads->isEmpty())
            <x-ui.empty-state
                icon="banknotes"
                title="No overheads found"
                :description="$search ? 'Try a different search term.' : 'Get started by recording your first overhead.'"
            >
                @unless($search)
                    <x-slot:action>
                        <flux:button variant="primary" :href="route('overheads.create')" wire:navigate>
                            New Overhead
                        </flux:button>
                    </x-slot:action>
                @endunless
            </x-ui.empty-state>
        @else
            <div x-data="zoneNav('table')" data-zone="table" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30">
                <table class="w-full text-sm">
                    <thead class="sticky top-14 lg:top-16 z-10 bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <x-ui.sortable-header column="expense_date" :state="$this->sortStateFor('expense_date')">Date</x-ui.sortable-header>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Category</th>
                            <x-ui.sortable-header column="amount" :state="$this->sortStateFor('amount')">Amount</x-ui.sortable-header>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">VAT</th>
                            <x-ui.sortable-header column="payment_method" :state="$this->sortStateFor('payment_method')">Payment Method</x-ui.sortable-header>
                            <th class="px-4 py-1"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->overheads as $overhead)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                data-view-url="{{ route('overheads.show', $overhead) }}"
                                data-edit-url="{{ route('overheads.edit', $overhead) }}"
                                @class([
                                    'transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5',
                                    'sticky bottom-0 z-10 bg-white dark:bg-zinc-900 shadow-[0_-1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_-1px_0_0_theme(--color-white/0.06)]' => $loop->last,
                                ])
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">
                                    {{ $overhead->expense_date->format('d M Y') }}
                                </td>
                                <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">
                                    {{ $overhead->category->name }}
                                </td>
                                <td class="px-4 py-2 font-mono text-zinc-900 dark:text-white">
                                    £{{ number_format($overhead->amount, 2) }}
                                </td>
                                <td class="px-4 py-2">
                                    @if($overhead->has_vat)
                                        <flux:icon.check-circle variant="solid" class="size-5 text-emerald-500" />
                                    @else
                                        <flux:icon.minus-circle variant="solid" class="size-5 text-zinc-300 dark:text-zinc-600" />
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">
                                    {{ $overhead->payment_method ?: '—' }}
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('overheads.show', $overhead)" wire:navigate data-row-action="view" />
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('overheads.edit', $overhead)" wire:navigate data-row-action="edit" />
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            icon="trash"
                                            wire:click="$set('deletingId', {{ $overhead->id }})"
                                            x-on:click="$flux.modal('delete-overhead').show()"
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
            <flux:pagination :paginator="$this->overheads" class="px-6" />
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

    {{-- Modals --}}
    <flux:modal name="delete-overhead" focusable class="max-w-sm" @close="$wire.set('deletingId', null)">
        <div class="space-y-4">
            <flux:heading>
                @if($this->deletingOverhead)
                    {{ __('Delete overhead from :date?', ['date' => $this->deletingOverhead->expense_date->format('d M Y')]) }}
                @endif
            </flux:heading>
            <flux:text class="text-zinc-500">This action cannot be undone.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteOverhead">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
