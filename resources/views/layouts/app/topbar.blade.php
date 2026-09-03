<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <style>:root { --app-font-size: {{ (int) \App\Models\Setting::get('app_font_size', 18) }}px; }</style>
    </head>
    <body class="min-h-screen bg-zinc-50 dark:bg-zinc-950 antialiased" data-is-admin="{{ auth()->user()?->isAdmin() ? '1' : '0' }}">

        {{-- ===================== TOPBAR ===================== --}}
        <header class="sticky top-0 z-40 border-b border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
            <div class="mx-auto flex h-14 max-w-[1600px] items-center gap-3 px-4">

                {{-- Brand --}}
                <a href="{{ route('dashboard') }}" wire:navigate class="flex shrink-0 items-center">
                    <img src="{{ asset('intellidb-logo.png') }}" alt="IntelliDB" class="h-9 rounded-md bg-white px-1.5 py-1">
                </a>

                {{-- Desktop nav strip --}}
                <nav class="ml-2 hidden items-center gap-0.5 lg:flex">
                    <a
                        href="{{ route('dashboard') }}"
                        wire:navigate
                        @class([
                            'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('dashboard'),
                            'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white' => !request()->routeIs('dashboard'),
                        ])
                    >
                        Dashboard
                        <kbd x-show="$store.hotkeys.showLabels" x-cloak class="rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 font-mono text-[10px] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">F9</kbd>
                    </a>

                    @canany(['customer-index', 'supplier-index'])
                    <flux:dropdown>
                        <button
                            type="button"
                            @class([
                                'inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('customers.*', 'suppliers.*'),
                                'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white' => !request()->routeIs('customers.*', 'suppliers.*'),
                            ])
                        >
                            Accounts
                            <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>
                        <flux:menu>
                            @can('customer-index')
                            <flux:menu.item :href="route('customers.index')" icon="user-group" wire:navigate>Customers</flux:menu.item>
                            @endcan
                            @can('supplier-index')
                            <flux:menu.item :href="route('suppliers.index')" icon="building-office" wire:navigate>Suppliers</flux:menu.item>
                            @endcan
                        </flux:menu>
                    </flux:dropdown>
                    @endcanany

                    @can('deliverynote-index')
                    <a
                        href="{{ route('delivery-notes.index') }}"
                        wire:navigate
                        @class([
                            'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('delivery-notes.*'),
                            'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white' => !request()->routeIs('delivery-notes.*'),
                        ])
                    >
                        Delivery Notes
                        <kbd x-show="$store.hotkeys.showLabels" x-cloak class="rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 font-mono text-[10px] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">F5</kbd>
                    </a>
                    @endcan

                    @can('export-index')
                    <a
                        href="{{ route('exports.index') }}"
                        wire:navigate
                        @class([
                            'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('exports.*'),
                            'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white' => !request()->routeIs('exports.*'),
                        ])
                    >
                        Exports
                    </a>
                    @endcan

                    @canany(['invoice-index', 'creditnote-index', 'payment-index', 'documentsearch-index', 'supplierinvoice-index', 'supplierdebitnote-index', 'supplierpayout-index', 'overhead-index'])
                    <flux:dropdown>
                        <button
                            type="button"
                            @class([
                                'inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('invoices.*', 'credit-notes.*', 'payments.*', 'overheads.*', 'supplier-invoices.*', 'supplier-debit-notes.*', 'supplier-payouts.*', 'document-search.*'),
                                'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white' => !request()->routeIs('invoices.*', 'credit-notes.*', 'payments.*', 'overheads.*', 'supplier-invoices.*', 'supplier-debit-notes.*', 'supplier-payouts.*', 'document-search.*'),
                            ])
                        >
                            Documents
                            <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>
                        <flux:menu>
                            @canany(['invoice-index', 'creditnote-index', 'payment-index', 'documentsearch-index'])
                            <div class="flex items-center gap-1.5 px-2 pb-1.5 pt-2 text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">
                                <flux:icon.user-group variant="micro" />
                                Customers
                            </div>
                            @endcanany
                            @can('invoice-index')
                            <flux:menu.item :href="route('invoices.index')" icon="document-text" wire:navigate>Invoices</flux:menu.item>
                            @endcan
                            @can('creditnote-index')
                            <flux:menu.item :href="route('credit-notes.index')" icon="receipt-refund" wire:navigate>Credit Notes</flux:menu.item>
                            @endcan
                            @can('payment-index')
                            <flux:menu.item :href="route('payments.index')" icon="credit-card" wire:navigate>Payments</flux:menu.item>
                            @endcan
                            @can('documentsearch-index')
                            <flux:menu.item :href="route('document-search.index')" icon="magnifying-glass" wire:navigate>Document Search</flux:menu.item>
                            @endcan

                            @canany(['supplierinvoice-index', 'supplierdebitnote-index', 'supplierpayout-index'])
                            <div class="mt-1.5 flex items-center gap-1.5 border-t border-zinc-200/70 px-2 pb-1.5 pt-2.5 text-xs font-semibold uppercase tracking-wide text-emerald-600 dark:border-white/10 dark:text-emerald-400">
                                <flux:icon.building-office variant="micro" />
                                Suppliers
                            </div>
                            @endcanany
                            @can('supplierinvoice-index')
                            <flux:menu.item :href="route('supplier-invoices.index')" icon="receipt-percent" wire:navigate>Supplier Invoices</flux:menu.item>
                            @endcan
                            @can('supplierdebitnote-index')
                            <flux:menu.item :href="route('supplier-debit-notes.index')" icon="minus-circle" wire:navigate>Supplier Debit Notes</flux:menu.item>
                            @endcan
                            @can('supplierpayout-index')
                            <flux:menu.item :href="route('supplier-payouts.index')" icon="banknotes" wire:navigate>Supplier Payouts</flux:menu.item>
                            @endcan

                            @can('overhead-index')
                            <div class="mt-1.5 flex items-center gap-1.5 border-t border-zinc-200/70 px-2 pb-1.5 pt-2.5 text-xs font-semibold uppercase tracking-wide text-amber-600 dark:border-white/10 dark:text-amber-400">
                                <flux:icon.wallet variant="micro" />
                                Expenses
                            </div>
                            <flux:menu.item :href="route('overheads.index')" icon="arrow-trending-up" wire:navigate>Overheads</flux:menu.item>
                            @endcan
                        </flux:menu>
                    </flux:dropdown>
                    @endcanany

                    @canany(['report-supplierPurchasing', 'report-overheads', 'report-customerOutstandingPayments', 'report-customerTurnover'])
                    <flux:dropdown>
                        <button
                            type="button"
                            @class([
                                'inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reports.*'),
                                'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white' => !request()->routeIs('reports.*'),
                            ])
                        >
                            Reports
                            <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>
                        <flux:menu>
                            @can('report-supplierPurchasing')
                            <flux:menu.item :href="route('reports.supplier-purchasing')" wire:navigate>
                                <x-slot:icon>
                                    <flux:icon icon="chart-bar" variant="mini" class="me-2 text-emerald-600 dark:text-emerald-400" />
                                </x-slot:icon>
                                Supplier Purchasing
                            </flux:menu.item>
                            @endcan
                            @can('report-overheads')
                            <flux:menu.item :href="route('reports.overheads')" wire:navigate>
                                <x-slot:icon>
                                    <flux:icon icon="chart-pie" variant="mini" class="me-2 text-amber-600 dark:text-amber-400" />
                                </x-slot:icon>
                                Overhead Report
                            </flux:menu.item>
                            @endcan
                            @can('report-customerOutstandingPayments')
                            <flux:menu.item :href="route('reports.customer-outstanding-payments')" wire:navigate>
                                <x-slot:icon>
                                    <flux:icon icon="banknotes" variant="mini" class="me-2 text-indigo-600 dark:text-indigo-400" />
                                </x-slot:icon>
                                Customer Outstanding
                            </flux:menu.item>
                            @endcan
                            @can('report-customerTurnover')
                            <flux:menu.item :href="route('reports.customer-turnover')" wire:navigate>
                                <x-slot:icon>
                                    <flux:icon icon="arrow-trending-up" variant="mini" class="me-2 text-indigo-600 dark:text-indigo-400" />
                                </x-slot:icon>
                                Customer Turnover
                            </flux:menu.item>
                            @endcan
                        </flux:menu>
                    </flux:dropdown>
                    @endcanany

                    @if(auth()->user()?->isAdmin())
                        {{-- Reference Data dropdown --}}
                        @canany(['referencedata-titles', 'referencedata-creditTerms', 'referencedata-creditLimits', 'referencedata-units', 'referencedata-paymentMethods', 'referencedata-expenseCategories', 'referencedata-customerCategories', 'referencedata-revenueTypes'])
                        <flux:dropdown>
                            <button
                                type="button"
                                @class([
                                    'inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.*'),
                                    'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white' => !request()->routeIs('reference-data.*'),
                                ])
                            >
                                Reference
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                </svg>
                            </button>
                            <flux:menu>
                                @can('referencedata-titles')
                                <flux:menu.item :href="route('reference-data.titles')" icon="tag" wire:navigate>Titles</flux:menu.item>
                                @endcan
                                @can('referencedata-creditTerms')
                                <flux:menu.item :href="route('reference-data.credit-terms')" icon="calendar-days" wire:navigate>Credit Terms</flux:menu.item>
                                @endcan
                                @can('referencedata-creditLimits')
                                <flux:menu.item :href="route('reference-data.credit-limits')" icon="shield-check" wire:navigate>Credit Limits</flux:menu.item>
                                @endcan
                                @can('referencedata-units')
                                <flux:menu.item :href="route('reference-data.units')" icon="scale" wire:navigate>Units</flux:menu.item>
                                @endcan
                                @can('referencedata-paymentMethods')
                                <flux:menu.item :href="route('reference-data.payment-methods')" icon="wallet" wire:navigate>Payment Methods</flux:menu.item>
                                @endcan
                                @can('referencedata-expenseCategories')
                                <flux:menu.item :href="route('reference-data.expense-categories')" icon="rectangle-stack" wire:navigate>Expense Categories</flux:menu.item>
                                @endcan
                                @can('referencedata-customerCategories')
                                <flux:menu.item :href="route('reference-data.customer-categories')" icon="tag" wire:navigate>Customer Categories</flux:menu.item>
                                @endcan
                                @can('referencedata-revenueTypes')
                                <flux:menu.item :href="route('reference-data.revenue-types')" icon="banknotes" wire:navigate>Revenue Types</flux:menu.item>
                                @endcan
                                @can('settings-legacyMigration')
                                <flux:menu.item :href="route('settings.legacy-migration')" icon="circle-stack" wire:navigate>Legacy Migration</flux:menu.item>
                                @endcan
                            </flux:menu>
                        </flux:dropdown>
                        @endcanany

                        @can('user-index')
                        <a
                            href="{{ route('users.index') }}"
                            wire:navigate
                            @class([
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('users.*'),
                                'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white' => !request()->routeIs('users.*'),
                            ])
                        >
                            Users
                        </a>
                        @endcan

                        @can('settings-crm')
                        <a
                            href="{{ route('settings.crm') }}"
                            wire:navigate
                            @class([
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('settings.crm'),
                                'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white' => !request()->routeIs('settings.crm'),
                            ])
                        >
                            Settings
                            <kbd x-show="$store.hotkeys.showLabels" x-cloak class="rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 font-mono text-[10px] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">F11</kbd>
                        </a>
                        @endcan
                    @endif
                </nav>

                {{-- Right side: + New dropdown + help + user --}}
                <div class="ml-auto flex items-center gap-1.5">
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="primary" icon="plus" icon-trailing="chevron-down">
                            New
                        </flux:button>
                        <flux:menu>
                            <div class="flex items-center gap-1.5 px-2 pb-1.5 pt-2 text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">
                                <flux:icon.user-group variant="micro" />
                                Customer Flow
                            </div>
                            <flux:menu.item :href="route('customers.create')" icon="user-plus" wire:navigate>
                                {{ __('Customer') }}
                                <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-2 rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 font-mono text-[10px] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">F1</kbd>
                            </flux:menu.item>
                            <flux:menu.item :href="route('delivery-notes.create')" icon="truck" wire:navigate>
                                {{ __('Delivery Note') }}
                            </flux:menu.item>
                            <flux:menu.item :href="route('credit-notes.create')" icon="receipt-refund" wire:navigate>
                                {{ __('Credit Note') }}
                            </flux:menu.item>
                            <flux:menu.item :href="route('payments.create')" icon="banknotes" wire:navigate>
                                {{ __('Payment') }}
                            </flux:menu.item>

                            <div class="mt-1.5 flex items-center gap-1.5 border-t border-zinc-200/70 px-2 pb-1.5 pt-2.5 text-xs font-semibold uppercase tracking-wide text-emerald-600 dark:border-white/10 dark:text-emerald-400">
                                <flux:icon.building-office variant="micro" />
                                Supplier Flow
                            </div>
                            <flux:menu.item :href="route('suppliers.create')" icon="building-office" wire:navigate>
                                {{ __('Supplier') }}
                            </flux:menu.item>
                            <flux:menu.item :href="route('supplier-invoices.create')" icon="receipt-percent" wire:navigate>
                                {{ __('Supplier Invoice') }}
                            </flux:menu.item>
                            <flux:menu.item :href="route('supplier-debit-notes.create')" icon="minus-circle" wire:navigate>
                                {{ __('Supplier Debit Note') }}
                            </flux:menu.item>

                            <div class="mt-1.5 flex items-center gap-1.5 border-t border-zinc-200/70 px-2 pb-1.5 pt-2.5 text-xs font-semibold uppercase tracking-wide text-amber-600 dark:border-white/10 dark:text-amber-400">
                                <flux:icon.wallet variant="micro" />
                                Expenditure
                            </div>
                            <flux:menu.item :href="route('overheads.create')" icon="arrow-trending-up" wire:navigate>
                                {{ __('Overhead') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>

                    {{-- User dropdown --}}
                    <flux:dropdown position="bottom" align="end">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-lg px-2 py-1 transition-colors hover:bg-zinc-100 dark:hover:bg-white/5"
                            data-test="topbar-menu-button"
                        >
                            <x-ui.avatar :name="auth()->user()->name" size="xs" />
                            <span class="hidden text-sm font-medium text-zinc-700 sm:inline dark:text-zinc-300">{{ auth()->user()->name }}</span>
                            <svg class="size-3 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>
                        <flux:menu>
                            <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>{{ __('Profile') }}</flux:menu.item>
                            <flux:menu.separator />
                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item
                                    as="button"
                                    type="submit"
                                    icon="arrow-right-start-on-rectangle"
                                    class="w-full cursor-pointer"
                                    data-test="logout-button"
                                >
                                    {{ __('Log out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>

                    {{-- Mobile hamburger --}}
                    <button
                        type="button"
                        x-data
                        x-on:click="$dispatch('open-mobile-nav')"
                        class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 lg:hidden dark:hover:bg-white/5"
                        aria-label="Open navigation"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Mobile nav (slide-down panel) --}}
            <div
                x-data="{ open: false }"
                x-on:open-mobile-nav.window="open = true"
                x-on:keydown.escape.window="open = false"
                x-cloak
                class="lg:hidden"
            >
                <div
                    x-show="open"
                    x-transition.opacity
                    class="fixed inset-0 z-40 bg-zinc-950/50"
                    x-on:click="open = false"
                ></div>
                <nav
                    x-show="open"
                    x-transition
                    class="fixed inset-x-0 top-14 z-50 max-h-[calc(100vh-3.5rem)] overflow-y-auto border-b border-zinc-200/70 bg-white shadow-lg dark:border-white/10 dark:bg-zinc-900"
                >
                    <ul class="flex flex-col space-y-0.5 p-2" x-on:click="open = false">
                        <li>
                            <a
                                href="{{ route('dashboard') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('dashboard'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('dashboard'),
                                ])
                            >
                                <flux:icon.home class="size-5 shrink-0" />
                                Dashboard
                            </a>
                        </li>
                        @can('customer-index')
                        <li>
                            <a
                                href="{{ route('customers.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('customers.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('customers.*'),
                                ])
                            >
                                <flux:icon.users class="size-5 shrink-0" />
                                Customers
                            </a>
                        </li>
                        @endcan
                        @can('supplier-index')
                        <li>
                            <a
                                href="{{ route('suppliers.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('suppliers.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('suppliers.*'),
                                ])
                            >
                                <flux:icon.building-office class="size-5 shrink-0" />
                                Suppliers
                            </a>
                        </li>
                        @endcan
                        @can('deliverynote-index')
                        <li>
                            <a
                                href="{{ route('delivery-notes.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('delivery-notes.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('delivery-notes.*'),
                                ])
                            >
                                <flux:icon.truck class="size-5 shrink-0" />
                                Delivery Notes
                            </a>
                        </li>
                        @endcan
                        @can('export-index')
                        <li>
                            <a
                                href="{{ route('exports.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('exports.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('exports.*'),
                                ])
                            >
                                <flux:icon.arrow-down-tray class="size-5 shrink-0" />
                                Exports
                            </a>
                        </li>
                        @endcan
                        @can('report-overheads')
                        <li>
                            <a
                                href="{{ route('reports.overheads') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reports.overheads'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reports.overheads'),
                                ])
                            >
                                <flux:icon.chart-bar class="size-5 shrink-0" />
                                Overhead Report
                            </a>
                        </li>
                        @endcan
                        @can('report-supplierPurchasing')
                        <li>
                            <a
                                href="{{ route('reports.supplier-purchasing') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reports.supplier-purchasing'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reports.supplier-purchasing'),
                                ])
                            >
                                <flux:icon.building-storefront class="size-5 shrink-0" />
                                Supplier Purchasing
                            </a>
                        </li>
                        @endcan
                        @can('report-customerOutstandingPayments')
                        <li>
                            <a
                                href="{{ route('reports.customer-outstanding-payments') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reports.customer-outstanding-payments'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reports.customer-outstanding-payments'),
                                ])
                            >
                                <flux:icon.banknotes class="size-5 shrink-0" />
                                Customer Outstanding
                            </a>
                        </li>
                        @endcan
                        @can('report-customerTurnover')
                        <li>
                            <a
                                href="{{ route('reports.customer-turnover') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reports.customer-turnover'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reports.customer-turnover'),
                                ])
                            >
                                <flux:icon.arrow-trending-up class="size-5 shrink-0" />
                                Customer Turnover
                            </a>
                        </li>
                        @endcan
                        @can('overhead-index')
                        <li>
                            <a
                                href="{{ route('overheads.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('overheads.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('overheads.*'),
                                ])
                            >
                                <flux:icon.banknotes class="size-5 shrink-0" />
                                Overheads
                            </a>
                        </li>
                        @endcan
                        @can('invoice-index')
                        <li>
                            <a
                                href="{{ route('invoices.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('invoices.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('invoices.*'),
                                ])
                            >
                                <flux:icon.document-text class="size-5 shrink-0" />
                                Invoices
                            </a>
                        </li>
                        @endcan
                        @can('creditnote-index')
                        <li>
                            <a
                                href="{{ route('credit-notes.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('credit-notes.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('credit-notes.*'),
                                ])
                            >
                                <flux:icon.receipt-refund class="size-5 shrink-0" />
                                Credit Notes
                            </a>
                        </li>
                        @endcan
                        @can('payment-index')
                        <li>
                            <a
                                href="{{ route('payments.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('payments.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('payments.*'),
                                ])
                            >
                                <flux:icon.banknotes class="size-5 shrink-0" />
                                Payments
                            </a>
                        </li>
                        @endcan
                        @can('documentsearch-index')
                        <li>
                            <a
                                href="{{ route('document-search.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('document-search.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('document-search.*'),
                                ])
                            >
                                <flux:icon.magnifying-glass class="size-5 shrink-0" />
                                Document Search
                            </a>
                        </li>
                        @endcan
                        @can('supplierinvoice-index')
                        <li>
                            <a
                                href="{{ route('supplier-invoices.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('supplier-invoices.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('supplier-invoices.*'),
                                ])
                            >
                                <flux:icon.receipt-percent class="size-5 shrink-0" />
                                Supplier Invoices
                            </a>
                        </li>
                        @endcan
                        @can('supplierdebitnote-index')
                        <li>
                            <a
                                href="{{ route('supplier-debit-notes.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('supplier-debit-notes.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('supplier-debit-notes.*'),
                                ])
                            >
                                <flux:icon.minus-circle class="size-5 shrink-0" />
                                Supplier Debit Notes
                            </a>
                        </li>
                        @endcan
                        @can('supplierpayout-index')
                        <li>
                            <a
                                href="{{ route('supplier-payouts.index') }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('supplier-payouts.*'),
                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('supplier-payouts.*'),
                                ])
                            >
                                <flux:icon.banknotes class="size-5 shrink-0" />
                                Supplier Payouts
                            </a>
                        </li>
                        @endcan

                        @if(auth()->user()?->isAdmin())
                            <li class="pt-2">
                                <p class="mb-1 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Admin</p>
                            </li>
                            @can('referencedata-titles')
                            <li>
                                <a
                                    href="{{ route('reference-data.titles') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.titles'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.titles'),
                                    ])
                                >
                                    <flux:icon.tag class="size-5 shrink-0" />
                                    Titles
                                </a>
                            </li>
                            @endcan
                            @can('referencedata-creditTerms')
                            <li>
                                <a
                                    href="{{ route('reference-data.credit-terms') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.credit-terms'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.credit-terms'),
                                    ])
                                >
                                    <flux:icon.tag class="size-5 shrink-0" />
                                    Credit Terms
                                </a>
                            </li>
                            @endcan
                            @can('referencedata-creditLimits')
                            <li>
                                <a
                                    href="{{ route('reference-data.credit-limits') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.credit-limits'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.credit-limits'),
                                    ])
                                >
                                    <flux:icon.tag class="size-5 shrink-0" />
                                    Credit Limits
                                </a>
                            </li>
                            @endcan
                            @can('referencedata-units')
                            <li>
                                <a
                                    href="{{ route('reference-data.units') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.units'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.units'),
                                    ])
                                >
                                    <flux:icon.tag class="size-5 shrink-0" />
                                    Units
                                </a>
                            </li>
                            @endcan
                            @can('referencedata-paymentMethods')
                            <li>
                                <a
                                    href="{{ route('reference-data.payment-methods') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.payment-methods'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.payment-methods'),
                                    ])
                                >
                                    <flux:icon.tag class="size-5 shrink-0" />
                                    Payment Methods
                                </a>
                            </li>
                            @endcan
                            @can('referencedata-expenseCategories')
                            <li>
                                <a
                                    href="{{ route('reference-data.expense-categories') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.expense-categories'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.expense-categories'),
                                    ])
                                >
                                    <flux:icon.tag class="size-5 shrink-0" />
                                    Expense Categories
                                </a>
                            </li>
                            @endcan
                            @can('referencedata-customerCategories')
                            <li>
                                <a
                                    href="{{ route('reference-data.customer-categories') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.customer-categories'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.customer-categories'),
                                    ])
                                >
                                    <flux:icon.tag class="size-5 shrink-0" />
                                    Customer Categories
                                </a>
                            </li>
                            @endcan
                            @can('referencedata-revenueTypes')
                            <li>
                                <a
                                    href="{{ route('reference-data.revenue-types') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.revenue-types'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.revenue-types'),
                                    ])
                                >
                                    <flux:icon.banknotes class="size-5 shrink-0" />
                                    Revenue Types
                                </a>
                            </li>
                            @endcan
                            @can('settings-legacyMigration')
                            <li>
                                <a
                                    href="{{ route('settings.legacy-migration') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('settings.legacy-migration'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('settings.legacy-migration'),
                                    ])
                                >
                                    <flux:icon.circle-stack class="size-5 shrink-0" />
                                    Legacy Migration
                                </a>
                            </li>
                            @endcan
                            @can('user-index')
                            <li>
                                <a
                                    href="{{ route('users.index') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('users.*'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('users.*'),
                                    ])
                                >
                                    <flux:icon.user-group class="size-5 shrink-0" />
                                    Users
                                </a>
                            </li>
                            @endcan
                            @can('settings-crm')
                            <li>
                                <a
                                    href="{{ route('settings.crm') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('settings.crm'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('settings.crm'),
                                    ])
                                >
                                    <flux:icon.cog-6-tooth class="size-5 shrink-0" />
                                    Settings
                                </a>
                            </li>
                            @endcan
                        @endif
                    </ul>
                </nav>
            </div>
        </header>

        {{-- ===================== MAIN CONTENT ===================== --}}
        <main class="bg-zinc-50 dark:bg-zinc-950">
            <div class="mx-auto max-w-[1600px] px-6 py-6 md:px-8 md:py-8">

                @if (session('error'))
                    <div
                        x-data="{ show: true }"
                        x-show="show"
                        x-init="setTimeout(() => show = false, 5000)"
                        class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800 shadow-sm dark:border-rose-800/50 dark:bg-rose-900/20 dark:text-rose-300"
                    >
                        <div class="flex items-start gap-3">
                            <flux:icon.x-circle class="mt-0.5 size-5 shrink-0" />
                            <div class="text-sm">{{ session('error') }}</div>
                        </div>
                    </div>
                @endif

                {{-- Hotkeys store init --}}
                <div x-data x-init="Alpine.store('hotkeys').init()"></div>

                {{-- Hotkeys help modal --}}
                @include('partials.hotkeys-help-modal')

                {{ $slot }}
            </div>
        </main>

        @persist('toast')
            <flux:toast.group position="middle center" class="flex flex-col gap-2">
                <flux:toast class="in-[ui-toast-group]:w-sm sm:in-[ui-toast-group]:w-md" />
            </flux:toast.group>
        @endpersist

        {{-- Hidden logout form for F12 shortcut --}}
        <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>

        @fluxScripts
    </body>
</html>
