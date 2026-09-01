<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Customer;
use App\Traits\WithPerPage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Customers')] class extends Component {
    use WithSorting;
    use WithPerPage;
    use \Livewire\WithPagination;

    protected array $sortable = ['company_name', 'reference', 'email_1', 'created_at'];

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Url]
    public array $filters = [
        'reference' => '',
        'company' => '',
        'contact' => '',
        'email' => '',
        'vat' => '',
    ];

    public function updatedFilters(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function hasActiveFilter(): bool
    {
        return $this->search !== '' || collect($this->filters)->filter(fn ($v) => $v !== '')->isNotEmpty();
    }

    public int $perPage = 25;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function customers()
    {
        $query = Customer::query()
            ->when($this->search, fn ($q) => $q->where(function ($sub) {
                $sub->where('company_name', 'like', "%{$this->search}%")
                    ->orWhere('reference', 'like', "%{$this->search}%")
                    ->orWhere('email_1', 'like', "%{$this->search}%");
            }))
            ->when($this->filters['reference'], fn ($q, $v) => $q->where('reference', 'like', "%{$v}%"))
            ->when($this->filters['company'], fn ($q, $v) => $q->where('company_name', 'like', "%{$v}%"))
            ->when($this->filters['email'], fn ($q, $v) => $q->where('email_1', 'like', "%{$v}%"))
            ->when($this->filters['contact'], fn ($q, $v) => $q->where(function ($sub) use ($v) {
                $sub->where('first_name', 'like', "%{$v}%")
                    ->orWhere('last_name', 'like', "%{$v}%")
                    ->orWhereHas('title', fn ($t) => $t->where('name', 'like', "%{$v}%"));
            }))
            ->when($this->filters['vat'] !== '', fn ($q) => $q->where('vat_registered', (bool) $this->filters['vat']));

        return $this->sortColumn === ''
            ? $this->applySort($query)->latest('id')->paginate($this->perPage)
            : $this->applySort($query)->paginate($this->perPage);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Customers"
        subtitle="Manage companies and their trading terms."
    >
        <x-slot:action>
            @can('customer-create')
            <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>
                New Customer
            </flux:button>
            @endcan
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
                    :placeholder="__('Search by company, reference or email…')"
                    clearable
                    class="max-w-sm"
                />
            </div>
            <x-ui.per-page-select />
        </div>
    </div>

    {{-- Table card --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">

        @if($this->customers->isEmpty() && ! $this->hasActiveFilter)
            <x-ui.empty-state
                icon="users"
                title="No customers found"
                description="Get started by creating your first customer."
            >
                @unless($this->hasActiveFilter)
                    <x-slot:action>
                        @can('customer-create')
                        <flux:button variant="primary" :href="route('customers.create')" wire:navigate>
                            New Customer
                        </flux:button>
                        @endcan
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
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Contact</th>
                            <x-ui.sortable-header column="email_1" :state="$this->sortStateFor('email_1')">Email</x-ui.sortable-header>
                            <th class="px-4 py-1 text-center text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">VAT</th>
                            <th class="px-4 py-1"></th>
                        </tr>
                        <tr>
                            <th class="px-4 py-1"><x-ui.column-filter model="filters.reference" :placeholder="__('Filter…')" /></th>
                            <th class="px-4 py-1"><x-ui.column-filter model="filters.company" :placeholder="__('Filter…')" /></th>
                            <th class="px-4 py-1"><x-ui.column-filter model="filters.contact" :placeholder="__('Filter…')" /></th>
                            <th class="px-4 py-1"><x-ui.column-filter model="filters.email" :placeholder="__('Filter…')" /></th>
                            <th class="px-4 py-1">
                                <x-ui.column-filter-select model="filters.vat">
                                    <flux:select.option value="">{{ __('All') }}</flux:select.option>
                                    <flux:select.option value="1">{{ __('VAT reg.') }}</flux:select.option>
                                    <flux:select.option value="0">{{ __('Not reg.') }}</flux:select.option>
                                </x-ui.column-filter-select>
                            </th>
                            <th class="px-4 py-1"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @forelse($this->customers as $customer)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                @can('customer-show') data-view-url="{{ route('customers.show', $customer) }}" @endcan
                                @can('customer-edit') data-edit-url="{{ route('customers.edit', $customer) }}" @endcan
                                @can('customer-delete') data-delete-modal="delete-customer-{{ $customer->id }}" @endcan
                                @class([
                                    'transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5',
                                    'sticky bottom-0 z-10 bg-white dark:bg-zinc-900 shadow-[0_-1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_-1px_0_0_theme(--color-white/0.06)]' => false && $loop->last, // sticky first/last row disabled
                                    'sticky top-[5.75rem] lg:top-[6.25rem] z-10 bg-white dark:bg-zinc-900 shadow-[0_1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_1px_0_0_theme(--color-white/0.06)]' => false && $loop->first,
                                ])
                                :class="{ '!bg-indigo-50 dark:!bg-indigo-500/10 ring-2 ring-inset ring-indigo-500/30': $store.hotkeys.selectedRow === {{ $loop->index }} }"
                            >
                                <td class="px-4 py-2 font-mono text-sm text-zinc-500 dark:text-zinc-400">
                                    @if($customer->reference)
                                        <x-ui.highlight :text="$customer->reference" :term="$search" />
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :name="$customer->company_name" size="sm" />
                                        @can('customer-show')
                                        <a href="{{ route('customers.show', $customer) }}" wire:navigate class="font-semibold text-zinc-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400 transition-colors">
                                            <x-ui.highlight :text="$customer->company_name" :term="$search" />
                                        </a>
                                        @else
                                        <span class="font-semibold text-zinc-900 dark:text-white">
                                            <x-ui.highlight :text="$customer->company_name" :term="$search" />
                                        </span>
                                        @endcan
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-zinc-600 dark:text-zinc-400">
                                    {{ trim(($customer->title?->name ? $customer->title->name.' ' : '').$customer->first_name.' '.$customer->last_name) ?: '—' }}
                                </td>
                                <td class="px-6 py-4 text-zinc-600 dark:text-zinc-400">@if($customer->email_1)<x-ui.highlight :text="$customer->email_1" :term="$search" />@else—@endif</td>
                                <td class="px-4 py-2 text-center">
                                    @if($customer->vat_registered)
                                        <flux:icon.check-circle variant="solid" class="size-5 text-emerald-500" />
                                    @else
                                        <flux:icon.minus-circle variant="solid" class="size-5 text-zinc-300 dark:text-zinc-600" />
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('customer-show')
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('customers.show', $customer)" wire:navigate data-row-action="view" />
                                        @endcan
                                        @can('customer-edit')
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('customers.edit', $customer)" wire:navigate data-row-action="edit" />
                                        @endcan
                                        @can('customer-delete')
                                        <livewire:pages::customers.delete-modal :customer="$customer" :key="'delete-'.$customer->id" />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-0 py-0">
                                    <x-ui.empty-state
                                        icon="users"
                                        title="No customers found"
                                        description="Try adjusting your filters or search term."
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Footer: pagination --}}
            @if($this->customers->isNotEmpty())
                <flux:pagination :paginator="$this->customers" class="px-6" />
            @endif
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

</div>
