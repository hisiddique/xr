<?php

use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customers')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->when($this->search, fn ($q) => $q->where('company_name', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%")
                ->orWhere('email_1', 'like', "%{$this->search}%")
            )
            ->latest()
            ->paginate(15);
    }
}; ?>

<div class="flex flex-col gap-8">

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
    <div class="rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search by company, reference or email…')"
            clearable
            class="max-w-sm"
        />
    </div>

    {{-- Table card --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">

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
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Company</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Email</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                        @foreach($this->customers as $customer)
                            <tr class="transition-colors hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :name="$customer->company_name" size="sm" />
                                        <div>
                                            <a href="{{ route('customers.show', $customer) }}" wire:navigate class="font-semibold text-zinc-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400 transition-colors">
                                                {{ $customer->company_name }}
                                            </a>
                                            @if($customer->reference)
                                                <p class="text-xs text-zinc-400 dark:text-zinc-500 font-mono">{{ $customer->reference }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-zinc-600 dark:text-zinc-400">
                                    {{ trim(($customer->title?->name ? $customer->title->name.' ' : '').$customer->first_name.' '.$customer->last_name) ?: '—' }}
                                </td>
                                <td class="px-6 py-4 text-zinc-600 dark:text-zinc-400">{{ $customer->email_1 ?? '—' }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="xs" variant="ghost" icon="eye" :href="route('customers.show', $customer)" wire:navigate />
                                        <flux:button size="xs" variant="ghost" icon="pencil" :href="route('customers.edit', $customer)" wire:navigate />
                                        <livewire:pages::customers.delete-modal :customer="$customer" :key="'delete-'.$customer->id" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-100 px-6 py-4 dark:border-white/[0.06]">
                {{ $this->customers->links() }}
            </div>
        @endif
    </div>

</div>
