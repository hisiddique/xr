<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Supplier;
use App\Traits\WithPerPage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Suppliers')] class extends Component {
    use WithSorting;
    use WithPerPage;
    use \Livewire\WithPagination;

    protected array $sortable = ['company_name', 'reference', 'created_at'];

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public int $perPage = 25;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function suppliers()
    {
        $query = Supplier::query()
            ->when($this->search, fn ($q) => $q->where('company_name', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%")
            );

        return $this->sortColumn === ''
            ? $this->applySort($query)->latest('id')->paginate($this->perPage)
            : $this->applySort($query)->paginate($this->perPage);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Suppliers"
        subtitle="Manage your supplier accounts."
    >
        <x-slot:action>
            <flux:button variant="primary" icon="plus" :href="route('suppliers.create')" wire:navigate>
                New Supplier
            </flux:button>
        </x-slot:action>
    </x-ui.page-header>

    {{-- Toolbar card --}}
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 dark:border-white/10 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-3">
            <div x-data="zoneNav('search')" data-zone="search" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30 rounded-lg max-w-sm">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    data-search-input
                    autocomplete="off"
                    icon="magnifying-glass"
                    :placeholder="__('Search by company or reference…')"
                    clearable
                    class="max-w-sm"
                />
            </div>
            <x-ui.per-page-select />
        </div>
    </div>

    {{-- Table card --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">

        @if($this->suppliers->isEmpty())
            <x-ui.empty-state
                icon="building-office"
                title="No suppliers found"
                :description="$search ? 'Try a different search term.' : 'Get started by creating your first supplier.'"
            >
                @unless($search)
                    <x-slot:action>
                        <flux:button variant="primary" :href="route('suppliers.create')" wire:navigate>
                            New Supplier
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
                            <x-ui.sortable-header column="company_name" :state="$this->sortStateFor('company_name')">Company</x-ui.sortable-header>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Trade Discount</th>
                            <th class="px-4 py-1 text-center text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">VAT Applied</th>
                            <th class="px-4 py-1"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->suppliers as $supplier)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                data-view-url="{{ route('suppliers.show', $supplier) }}"
                                data-edit-url="{{ route('suppliers.edit', $supplier) }}"
                                data-delete-modal="delete-supplier-{{ $supplier->id }}"
                                @class([
                                    'transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5',
                                    'sticky bottom-0 z-10 bg-white dark:bg-zinc-900 shadow-[0_-1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_-1px_0_0_theme(--color-white/0.06)]' => $loop->last,
                                    'sticky top-[5.75rem] lg:top-[6.25rem] z-10 bg-white dark:bg-zinc-900 shadow-[0_1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_1px_0_0_theme(--color-white/0.06)]' => $loop->first,
                                ])
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2 font-mono text-sm text-zinc-500 dark:text-zinc-400">
                                    @if($supplier->reference)
                                        <x-ui.highlight :text="$supplier->reference" :term="$search" />
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :name="$supplier->company_name" size="sm" />
                                        <a href="{{ route('suppliers.show', $supplier) }}" wire:navigate class="font-semibold text-zinc-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400 transition-colors">
                                            <x-ui.highlight :text="$supplier->company_name" :term="$search" />
                                        </a>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">
                                    @if($supplier->trade_discount > 0)
                                        <flux:badge color="sky">{{ $supplier->trade_discount }}%</flux:badge>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-center">
                                        @if($supplier->vat_applied)
                                            <flux:icon.check-circle variant="solid" class="size-5 text-emerald-500" />
                                        @else
                                            <flux:icon.minus-circle variant="solid" class="size-5 text-zinc-300 dark:text-zinc-600" />
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        <a
                                            href="{{ route('supplier-invoices.index').'?search='.urlencode($supplier->company_name) }}"
                                            wire:navigate
                                            data-row-action="invoices"
                                            class="inline-flex items-center gap-1 rounded-md border border-indigo-600/30 bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 transition-colors hover:bg-indigo-100 dark:border-indigo-400/30 dark:bg-indigo-400/10 dark:text-indigo-400 dark:hover:bg-indigo-400/20"
                                        >
                                            <flux:icon.receipt-percent class="h-3.5 w-3.5" />
                                            Invoices
                                        </a>
                                        <a
                                            href="{{ route('supplier-debit-notes.index').'?search='.urlencode($supplier->company_name) }}"
                                            wire:navigate
                                            data-row-action="debit-notes"
                                            class="inline-flex items-center gap-1 rounded-md border border-amber-600/30 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 transition-colors hover:bg-amber-100 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-400 dark:hover:bg-amber-400/20"
                                        >
                                            <flux:icon.document-minus class="h-3.5 w-3.5" />
                                            Debit Notes
                                        </a>
                                        <a
                                            href="{{ route('supplier-payouts.index').'?search='.urlencode($supplier->company_name) }}"
                                            wire:navigate
                                            data-row-action="payouts"
                                            class="inline-flex items-center gap-1 rounded-md border border-emerald-600/30 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 transition-colors hover:bg-emerald-100 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-400 dark:hover:bg-emerald-400/20"
                                        >
                                            <flux:icon.banknotes class="h-3.5 w-3.5" />
                                            Payouts
                                        </a>
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('suppliers.show', $supplier)" wire:navigate data-row-action="view" />
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('suppliers.edit', $supplier)" wire:navigate data-row-action="edit" />
                                        <livewire:pages::suppliers.delete-modal :supplier="$supplier" :key="'delete-'.$supplier->id" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Footer: pagination --}}
            <flux:pagination :paginator="$this->suppliers" class="px-6" />
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

</div>
