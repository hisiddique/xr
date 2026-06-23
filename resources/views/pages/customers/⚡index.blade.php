<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Customers')] class extends Component {
    use WithSorting;
    use \Livewire\WithPagination;

    protected array $sortable = ['company_name', 'reference', 'email_1', 'created_at'];

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
    public function customers()
    {
        $query = Customer::query()
            ->when($this->search, fn ($q) => $q->where('company_name', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%")
                ->orWhere('email_1', 'like', "%{$this->search}%")
            );

        return $this->sortColumn === ''
            ? $this->applySort($query)->oldest()->paginate($this->perPage)
            : $this->applySort($query)->paginate($this->perPage);
    }
}; ?>

<div class="flex flex-col gap-4">

    <x-ui.page-header
        title="Customers"
        subtitle="Manage companies and their trading terms."
    >
        <x-slot:action>
            <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>
                New Customer
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
                    :placeholder="__('Search by company, reference or email…')"
                    clearable
                    class="max-w-sm"
                />
            </div>
            <div
                x-data="{
                    init() {
                        const saved = localStorage.getItem('crm_per_page');
                        const valid = [25, 50, 100, 250, 500, 1000];
                        if (saved && valid.includes(Number(saved))) {
                            $wire.set('perPage', Number(saved), false);
                        }
                    }
                }"
                x-init="init()"
            >
                <select
                    wire:model.live="perPage"
                    x-on:change="localStorage.setItem('crm_per_page', $event.target.value)"
                    class="rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm text-zinc-700 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                >
                    @foreach([25, 50, 100, 250, 500, 1000] as $n)
                        <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }} per page</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Table card --}}
    <div class="overflow-x-clip rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">

        @if($this->customers->isEmpty())
            <x-ui.empty-state
                icon="users"
                title="No customers found"
                :description="$search ? 'Try a different search term.' : 'Get started by creating your first customer.'"
            >
                @unless($search)
                    <x-slot:action>
                        <flux:button variant="primary" :href="route('customers.create')" wire:navigate>
                            New Customer
                        </flux:button>
                    </x-slot:action>
                @endunless
            </x-ui.empty-state>
        @else
            <div x-data="zoneNav('table')" data-zone="table" tabindex="-1" class="outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/30">
                <table class="w-full text-sm">
                    <thead class="sticky top-14 lg:top-16 z-10 bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <x-ui.sortable-header column="reference" :state="$this->sortStateFor('reference')">Reference</x-ui.sortable-header>
                            <x-ui.sortable-header column="company_name" :state="$this->sortStateFor('company_name')">Company</x-ui.sortable-header>
                            <th class="px-4 py-1 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Contact</th>
                            <x-ui.sortable-header column="email_1" :state="$this->sortStateFor('email_1')">Email</x-ui.sortable-header>
                            <th class="px-4 py-1 text-center text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">VAT</th>
                            <th class="px-4 py-1"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->customers as $customer)
                            <tr
                                data-row-index="{{ $loop->index }}"
                                data-view-url="{{ route('customers.show', $customer) }}"
                                data-edit-url="{{ route('customers.edit', $customer) }}"
                                data-delete-modal="delete-customer-{{ $customer->id }}"
                                @class([
                                    'transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5',
                                    'sticky bottom-0 z-10 bg-white dark:bg-zinc-900 shadow-[0_-1px_0_0_theme(--color-zinc-100)] dark:shadow-[0_-1px_0_0_theme(--color-white/0.06)]' => $loop->last,
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
                                        <a href="{{ route('customers.show', $customer) }}" wire:navigate class="font-semibold text-zinc-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400 transition-colors">
                                            <x-ui.highlight :text="$customer->company_name" :term="$search" />
                                        </a>
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
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('customers.show', $customer)" wire:navigate data-row-action="view" />
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('customers.edit', $customer)" wire:navigate data-row-action="edit" />
                                        <livewire:pages::customers.delete-modal :customer="$customer" :key="'delete-'.$customer->id" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Footer: pagination --}}
            <flux:pagination :paginator="$this->customers" class="px-6" />
        @endif
    </div>

    <div x-data x-init="$nextTick(() => Alpine.store('hotkeys').focusZone('table'))"></div>

</div>
