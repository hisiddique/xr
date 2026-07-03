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
            'label' => 'Customer Operations', 'icon' => 'user-group', 'color' => 'indigo',
            'tiles' => [
                ['label' => 'Customers',      'icon' => 'users',          'href' => route('customers.index')],
                ['label' => 'Delivery Notes', 'icon' => 'truck',          'href' => route('delivery-notes.index')],
                ['label' => 'Invoices',       'icon' => 'document-text',  'href' => route('invoices.index')],
                ['label' => 'Credit Notes',   'icon' => 'receipt-refund', 'href' => route('credit-notes.index')],
                ['label' => 'Payments',       'icon' => 'banknotes',      'href' => route('payments.index')],
            ],
        ],
        [
            'label' => 'Supplier Operations', 'icon' => 'building-storefront', 'color' => 'emerald',
            'tiles' => [
                ['label' => 'Supplier Invoices',   'icon' => 'receipt-percent', 'href' => route('supplier-invoices.index')],
                ['label' => 'Supplier Purchasing', 'icon' => 'presentation-chart-line', 'href' => route('reports.supplier-purchasing'), 'badge' => 'Report'],
            ],
        ],
        [
            'label' => 'Overhead & Reports', 'icon' => 'chart-bar', 'color' => 'amber',
            'tiles' => [
                ['label' => 'Overheads',       'icon' => 'arrow-trending-up', 'href' => route('overheads.index')],
                ['label' => 'Overhead Report', 'icon' => 'chart-pie',         'href' => route('reports.overheads'), 'badge' => 'Report'],
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
            'label' => 'Identity & Team', 'icon' => 'identification', 'color' => 'violet',
            'tiles' => array_values(array_filter([
                $isAdmin ? ['label' => 'Users', 'icon' => 'user-group', 'href' => route('users.index')] : null,
                ['label' => 'Profile', 'icon' => 'user-circle', 'href' => route('profile.edit')],
            ])),
        ],
    ]));
@endphp

<div class="flex flex-col gap-5">

    {{-- Welcome banner (compact) --}}
    <div class="relative overflow-hidden rounded-xl bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 px-5 py-3 text-white">
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
        <div class="relative flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-base font-semibold tracking-tight">
                {{ $this->greeting }}, {{ explode(' ', auth()->user()->name)[0] }}
            </h1>
            <p class="text-xs font-medium text-indigo-100/90">{{ now()->format('l, d F Y') }}</p>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach([
            ['label' => 'Customers',      'value' => $this->customerCount,      'icon' => 'users',          'bar' => 'bg-indigo-500',  'iconBg' => 'bg-indigo-50 dark:bg-indigo-500/10',   'iconText' => 'text-indigo-600 dark:text-indigo-400',   'href' => route('customers.index')],
            ['label' => 'Delivery Notes', 'value' => $this->deliveryNoteCount,  'icon' => 'truck',          'bar' => 'bg-emerald-500', 'iconBg' => 'bg-emerald-50 dark:bg-emerald-500/10', 'iconText' => 'text-emerald-600 dark:text-emerald-400', 'href' => route('delivery-notes.index')],
            ['label' => 'Invoices',       'value' => $this->invoiceCount,       'icon' => 'document-text',  'bar' => 'bg-amber-500',   'iconBg' => 'bg-amber-50 dark:bg-amber-500/10',     'iconText' => 'text-amber-600 dark:text-amber-400',     'href' => route('invoices.index')],
            ['label' => 'Credit Notes',   'value' => $this->creditNoteCount,    'icon' => 'receipt-refund', 'bar' => 'bg-rose-500',    'iconBg' => 'bg-rose-50 dark:bg-rose-500/10',       'iconText' => 'text-rose-600 dark:text-rose-400',       'href' => route('credit-notes.index')],
        ] as $kpi)
            <a
                href="{{ $kpi['href'] }}"
                wire:navigate
                class="relative flex items-center gap-4 overflow-hidden rounded-xl border border-zinc-200/70 bg-white px-5 py-4 shadow-[0_1px_2px_rgba(16,24,40,0.06)] transition-colors hover:bg-zinc-50 dark:border-white/10 dark:bg-zinc-900 dark:hover:bg-zinc-800/60"
            >
                <div class="absolute inset-y-0 left-0 w-1 {{ $kpi['bar'] }}"></div>
                <div class="ml-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-lg {{ $kpi['iconBg'] }}">
                    <flux:icon :icon="$kpi['icon']" class="size-5 {{ $kpi['iconText'] }}" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $kpi['label'] }}</p>
                    <p class="text-2xl font-semibold leading-tight tracking-tight text-zinc-900 dark:text-white">{{ $kpi['value'] }}</p>
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
                <div class="grid grid-cols-2 gap-2.5">
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
