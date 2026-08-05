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
    public function creditNoteCount(): int
    {
        return Document::creditNotes()->count();
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

@php
    $isAdmin = auth()->user()?->isAdmin();

    $categories = array_values(array_filter([
        [
            'label' => 'Customer Operations Management', 'icon' => 'user-group', 'color' => 'blue',
            'tiles' => [
                ['label' => 'Customers',               'icon' => 'users',          'href' => route('customers.index')],
                ['label' => 'Customer Delivery Notes',  'icon' => 'truck',          'href' => route('delivery-notes.index')],
                ['label' => 'Customer Invoices',        'icon' => 'document-text',  'href' => route('invoices.index')],
                ['label' => 'Customer Credit Notes',    'icon' => 'receipt-refund', 'href' => route('credit-notes.index')],
                ['label' => 'Customer Payment',         'icon' => 'banknotes',      'href' => route('payments.index')],
                ['label' => 'Document Search',          'icon' => 'magnifying-glass', 'href' => route('document-search.index')],
                ['label' => 'Customer Outstanding',     'icon' => 'banknotes',      'href' => route('reports.customer-outstanding-payments'), 'badge' => 'Report'],
            ],
        ],
        [
            'label' => 'Supplier Operations Management', 'icon' => 'building-storefront', 'color' => 'emerald',
            'tiles' => [
                ['label' => 'Suppliers',            'icon' => 'shopping-bag',            'href' => route('suppliers.index')],
                ['label' => 'Supplier Invoices',     'icon' => 'receipt-percent',         'href' => route('supplier-invoices.index')],
                ['label' => 'Supplier Debit Note',  'icon' => 'document-minus',          'href' => route('supplier-debit-notes.index')],
                ['label' => 'Supplier Payout',      'icon' => 'banknotes',               'href' => route('supplier-payouts.index')],
                ['label' => 'Supplier Purchasing',  'icon' => 'presentation-chart-line', 'href' => route('reports.supplier-purchasing'), 'badge' => 'Report'],
            ],
        ],
        [
            'label' => 'Overhead Expenditure & Costs', 'icon' => 'chart-bar', 'color' => 'amber',
            'tiles' => [
                ['label' => 'Overhead Expenses', 'icon' => 'arrow-trending-up', 'href' => route('overheads.index')],
                ['label' => 'Overhead Reports',  'icon' => 'chart-pie',         'href' => route('reports.overheads'), 'badge' => 'Report'],
            ],
        ],
        $isAdmin ? [
            'label' => 'System References & Setup', 'icon' => 'cog-6-tooth', 'color' => 'slate',
            'tiles' => [
                ['label' => 'References', 'icon' => 'tag',         'modal' => 'references-menu'],
                ['label' => 'Settings',   'icon' => 'cog-6-tooth', 'href' => route('settings.crm')],
            ],
        ] : null,
        [
            'label' => 'Identity & Team Management', 'icon' => 'identification', 'color' => 'violet',
            'tiles' => array_values(array_filter([
                $isAdmin ? ['label' => 'Users', 'icon' => 'user-group', 'href' => route('users.index')] : null,
                ['label' => 'Profile', 'icon' => 'user-circle', 'href' => route('profile.edit')],
            ])),
        ],
    ]));
@endphp

<div class="flex flex-col gap-5">

    {{-- Welcome banner --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-600 px-8 py-3 text-white">
        <h1 class="text-2xl font-semibold tracking-tight">
            {{ $this->greeting }}, {{ explode(' ', auth()->user()->name)[0] }}
        </h1>
        <p class="flex items-center gap-2 rounded-lg bg-white/10 px-4 py-2 text-sm font-medium">
            <flux:icon icon="calendar-days" class="size-4" />
            {{ now()->format('l, d F Y') }}
        </p>
    </div>

    {{-- KPI Cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach([
            ['label' => 'Total Customers',    'value' => $this->customerCount,      'icon' => 'users',          'href' => route('customers.index')],
            ['label' => 'Delivery Notes',     'value' => $this->deliveryNoteCount,  'icon' => 'truck',          'href' => route('delivery-notes.index')],
            ['label' => 'Customer Invoices',  'value' => $this->invoiceCount,       'icon' => 'document-text',  'href' => route('invoices.index')],
            ['label' => 'Credit Notes',       'value' => $this->creditNoteCount,    'icon' => 'receipt-refund', 'href' => route('credit-notes.index')],
        ] as $kpi)
            <a
                href="{{ $kpi['href'] }}"
                wire:navigate
                class="relative flex items-center justify-between gap-4 overflow-hidden rounded-xl border border-zinc-200/70 bg-white p-6 shadow-[0_1px_2px_rgba(16,24,40,0.06)] transition-colors hover:bg-zinc-50 dark:border-white/10 dark:bg-zinc-900 dark:hover:bg-zinc-800/60"
            >
                <div class="absolute inset-y-0 left-0 w-1 bg-blue-600"></div>
                <div class="ml-1 min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $kpi['label'] }}</p>
                    <p class="text-3xl font-bold leading-tight tracking-tight text-zinc-900 dark:text-white">{{ $kpi['value'] }}</p>
                </div>
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-blue-50 dark:bg-blue-500/10">
                    <flux:icon :icon="$kpi['icon']" class="size-5 text-blue-600 dark:text-blue-400" />
                </div>
            </a>
        @endforeach
    </div>

    {{-- Quick Actions (category-grouped tile blocks) --}}
    <div class="flex flex-col gap-4">
        @foreach($categories as $category)
            <x-ui.category-block :label="$category['label']" :icon="$category['icon']" :color="$category['color']">
                @foreach($category['tiles'] as $tile)
                    <x-ui.action-tile
                        :label="$tile['label']"
                        :icon="$tile['icon']"
                        :color="$category['color']"
                        :href="$tile['href'] ?? null"
                        :modal="$tile['modal'] ?? null"
                        :badge="$tile['badge'] ?? null"
                    />
                @endforeach
            </x-ui.category-block>
        @endforeach
    </div>

    @if($isAdmin)
        <flux:modal name="references-menu" focusable class="max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">References</flux:heading>
                    <flux:subheading>Manage reference data.</flux:subheading>
                </div>
                <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                    <x-ui.action-tile label="Titles"        icon="identification" color="slate" :href="route('reference-data.titles')" />
                    <x-ui.action-tile label="Credit Terms"  icon="calendar-days"  color="slate" :href="route('reference-data.credit-terms')" />
                    <x-ui.action-tile label="Credit Limits" icon="banknotes"      color="slate" :href="route('reference-data.credit-limits')" />
                    <x-ui.action-tile label="Units"         icon="scale"          color="slate" :href="route('reference-data.units')" />
                </div>
                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="ghost" type="button">Close</flux:button></flux:modal.close>
                </div>
            </div>
        </flux:modal>
    @endif

</div>
