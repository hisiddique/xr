<?php

use App\Models\Customer;
use App\Models\Document;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function customerCount(): int
    {
        return Customer::count();
    }

    #[Computed]
    public function deliveryNoteCount(): int
    {
        return Document::deliveryNotes()->count();
    }

    #[Computed]
    public function invoiceCount(): int
    {
        return Document::invoices()->count();
    }

    #[Computed]
    public function totalRevenue(): float
    {
        return (float) Document::invoices()->sum('total_value');
    }

    #[Computed]
    public function recentDeliveryNotes()
    {
        return Document::deliveryNotes()
            ->with('customer')
            ->latest()
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function topCustomers()
    {
        return Customer::latest()->limit(5)->get();
    }

    #[Computed]
    public function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default    => 'Good evening',
        };
    }
}; ?>

<div class="flex flex-col gap-8">

    {{-- Welcome banner --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 p-6 text-white md:p-8">
        {{-- Subtle dot overlay --}}
        <div class="pointer-events-none absolute inset-0 opacity-[0.07]">
            <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <pattern id="banner-dots" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">
                        <circle cx="2" cy="2" r="1.2" fill="white" />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#banner-dots)" />
            </svg>
        </div>

        <div class="relative flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ $this->greeting }}, {{ explode(' ', auth()->user()->name)[0] }}
                </h1>
                <p class="mt-1 text-indigo-100 text-sm">Here's what's happening with your deliveries today.</p>
            </div>
            <p class="text-sm font-medium text-indigo-100 opacity-80">{{ now()->format('l, d F Y') }}</p>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card
            label="Customers"
            :value="$this->customerCount"
            color="indigo"
            icon="users"
            :href="route('customers.index')"
            link-label="View all"
        />
        <x-ui.stat-card
            label="Delivery Notes"
            :value="$this->deliveryNoteCount"
            color="emerald"
            icon="truck"
            :href="route('delivery-notes.index')"
            link-label="View all"
        />
        <x-ui.stat-card
            label="Invoices"
            :value="$this->invoiceCount"
            color="amber"
            icon="document-text"
            :href="route('invoices.index')"
            link-label="View all"
        />
        <x-ui.stat-card
            label="Total Revenue"
            :value="'£'.number_format($this->totalRevenue, 0)"
            color="violet"
            icon="banknotes"
        />
    </div>

    {{-- Two-column: Recent DNs + Top Customers --}}
    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Recent Delivery Notes (2/3 width) --}}
        <div class="lg:col-span-2">
            <x-ui.section-card :padding="false">
                <x-slot:header>
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Recent Delivery Notes</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Latest 5 documents</p>
                    </div>
                    <a href="{{ route('delivery-notes.index') }}" wire:navigate class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                        See all →
                    </a>
                </x-slot:header>

                @if($this->recentDeliveryNotes->isEmpty())
                    <x-ui.empty-state
                        icon="truck"
                        title="No delivery notes yet"
                        description="Create your first delivery note to start tracking deliveries."
                    >
                        <x-slot:action>
                            <flux:button variant="primary" size="sm" :href="route('delivery-notes.create')" wire:navigate>
                                Create Delivery Note
                            </flux:button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-100 dark:border-white/[0.06]">
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Customer</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Doc #</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                                @foreach($this->recentDeliveryNotes as $note)
                                    <tr class="transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-500/5">
                                        <td class="px-6 py-3.5">
                                            <div class="flex items-center gap-2.5">
                                                <x-ui.avatar :name="$note->customer->company_name" size="xs" />
                                                <span class="font-medium text-zinc-900 dark:text-white text-sm">{{ $note->customer->company_name }}</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-3.5">
                                            <a href="{{ route('delivery-notes.show', $note) }}" wire:navigate class="font-mono text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                                {{ $note->doc_number }}
                                            </a>
                                        </td>
                                        <td class="px-6 py-3.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $note->doc_date->format('d M Y') }}</td>
                                        <td class="px-6 py-3.5 text-right font-mono text-sm font-medium text-zinc-900 tabular-nums dark:text-white">£{{ number_format($note->total_value, 2) }}</td>
                                        <td class="px-6 py-3.5">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $note->status->ringColor() }}">
                                                {{ $note->status->label() }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.section-card>
        </div>

        {{-- Top Customers (1/3 width) --}}
        <div class="lg:col-span-1">
            <x-ui.section-card :padding="false">
                <x-slot:header>
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Top Customers</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Most recently added</p>
                    </div>
                    <a href="{{ route('customers.index') }}" wire:navigate class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                        See all →
                    </a>
                </x-slot:header>

                @if($this->topCustomers->isEmpty())
                    <x-ui.empty-state
                        icon="users"
                        title="No customers yet"
                        description="Add your first customer to get started."
                    >
                        <x-slot:action>
                            <flux:button variant="primary" size="sm" :href="route('customers.create')" wire:navigate>
                                Add Customer
                            </flux:button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <ul class="divide-y divide-zinc-50 dark:divide-white/[0.04]">
                        @foreach($this->topCustomers as $customer)
                            <li>
                                <a
                                    href="{{ route('customers.show', $customer) }}"
                                    wire:navigate
                                    class="flex items-center gap-3 px-5 py-3.5 transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-500/5"
                                >
                                    <x-ui.avatar :name="$customer->company_name" size="sm" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $customer->company_name }}</p>
                                        @if($customer->email_1)
                                            <p class="truncate text-xs text-zinc-400 dark:text-zinc-500">{{ $customer->email_1 }}</p>
                                        @endif
                                    </div>
                                    @if($customer->trade_discount > 0)
                                        <span class="shrink-0 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-400">
                                            {{ $customer->trade_discount }}% off
                                        </span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.section-card>
        </div>
    </div>

    {{-- Quick Actions strip --}}
    <div>
        <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Quick Actions</p>
        <div class="grid gap-4 sm:grid-cols-3">
            <a
                href="{{ route('customers.create') }}"
                wire:navigate
                class="group flex items-center gap-4 rounded-2xl border border-zinc-200/70 bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] transition-transform hover:-translate-y-0.5 dark:border-white/10 dark:bg-zinc-900"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 dark:bg-indigo-500/10">
                    <flux:icon.users class="size-5 text-indigo-600 dark:text-indigo-400" />
                </div>
                <div>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">New Customer</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Add a company or contact</p>
                </div>
            </a>
            <a
                href="{{ route('delivery-notes.create') }}"
                wire:navigate
                class="group flex items-center gap-4 rounded-2xl border border-zinc-200/70 bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] transition-transform hover:-translate-y-0.5 dark:border-white/10 dark:bg-zinc-900"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 dark:bg-emerald-500/10">
                    <flux:icon.truck class="size-5 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">New Delivery Note</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Log a new shipment</p>
                </div>
            </a>
            @if(auth()->user()?->isAdmin())
                <a
                    href="{{ route('reference-data.titles') }}"
                    wire:navigate
                    class="group flex items-center gap-4 rounded-2xl border border-zinc-200/70 bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] transition-transform hover:-translate-y-0.5 dark:border-white/10 dark:bg-zinc-900"
                >
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 dark:bg-amber-500/10">
                        <flux:icon.tag class="size-5 text-amber-600 dark:text-amber-400" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">Reference Data</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Titles, terms, units</p>
                    </div>
                </a>
            @endif
        </div>
    </div>

</div>
