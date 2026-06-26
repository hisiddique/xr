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

    $activeTiles = array_values(array_filter([
        ['key' => 'c', 'label' => 'Customers',      'icon' => 'users',          'href' => route('customers.index')],
        ['key' => 'd', 'label' => 'Delivery Notes', 'icon' => 'truck',          'href' => route('delivery-notes.index')],
        ['key' => 'i', 'label' => 'Invoices',       'icon' => 'document-text',  'href' => route('invoices.index')],
        ['key' => 'n', 'label' => 'Credit Notes',   'icon' => 'receipt-refund', 'href' => route('credit-notes.index')],
        ['key' => 'm', 'label' => 'Payments',       'icon' => 'banknotes',      'href' => route('payments.index')],
        ['key' => 'o', 'label' => 'Overheads',      'icon' => 'arrow-trending-up', 'href' => route('overheads.index')],
        ['key' => 'v', 'label' => 'Supplier Invoices', 'icon' => 'receipt-percent', 'href' => route('supplier-invoices.index')],
        $isAdmin ? ['key' => 'r', 'label' => 'References', 'icon' => 'tag', 'menu' => [
            ['label' => 'Titles',        'icon' => 'identification', 'href' => route('reference-data.titles')],
            ['label' => 'Credit Terms',  'icon' => 'calendar-days',  'href' => route('reference-data.credit-terms')],
            ['label' => 'Credit Limits', 'icon' => 'banknotes',      'href' => route('reference-data.credit-limits')],
            ['label' => 'Units',         'icon' => 'scale',          'href' => route('reference-data.units')],
        ]] : null,
        $isAdmin ? ['key' => 's', 'label' => 'Settings',   'icon' => 'cog-6-tooth',   'href' => route('settings.crm')]            : null,
        $isAdmin ? ['key' => 'u', 'label' => 'Users',      'icon' => 'user-group',    'href' => route('users.index')]             : null,
        ['key' => 'p', 'label' => 'Profile',        'icon' => 'user-circle',   'href' => route('profile.edit')],
    ]));

    $disabledTiles = [
        ['label' => 'Prospects',   'icon' => 'magnifying-glass'],
        ['label' => 'Accounts',    'icon' => 'building-library'],
        ['label' => 'Budgets',     'icon' => 'chart-pie'],
        ['label' => 'Company',     'icon' => 'building-office-2'],
        ['label' => 'Fax Queue',   'icon' => 'printer'],
        ['label' => 'Suppliers',   'icon' => 'building-storefront'],
        ['label' => 'Job Costing', 'icon' => 'clipboard-document-list'],
        ['label' => 'Time Log',    'icon' => 'clock'],
        ['label' => 'Contacts',    'icon' => 'identification'],
    ];
@endphp

<div
    class="flex flex-col gap-5"
    x-data="dashboardShortcuts(@js(collect($activeTiles)->pluck('key')->all()))"
    x-on:keydown.window="onKey($event)"
>

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

    {{-- Quick Actions (formal-system tile grid) --}}
    <div class="w-full">
        <div class="mb-3 flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Quick Actions</p>
            <p class="text-[11px] text-zinc-400 dark:text-zinc-500">Press the highlighted letter to jump</p>
        </div>

        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-4">
            @foreach($activeTiles as $tile)
                @php
                    $tileClasses = 'group relative flex flex-col items-center justify-center gap-2 rounded-xl border border-zinc-200/70 bg-white px-3 py-5 text-center shadow-[0_1px_2px_rgba(16,24,40,0.06)] transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md dark:border-white/10 dark:bg-zinc-900 dark:hover:border-indigo-500/40';
                    $badgeClasses = 'absolute right-2 top-2 inline-flex h-5 w-5 items-center justify-center rounded border border-zinc-200 bg-zinc-50 font-mono text-[10px] font-semibold uppercase text-zinc-500 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-400';
                @endphp

                @if(! empty($tile['menu']))
                    <flux:dropdown position="bottom" align="center" class="!block w-full">
                        <button type="button" data-shortcut="{{ $tile['key'] }}" class="{{ $tileClasses }} w-full cursor-pointer">
                            <span class="{{ $badgeClasses }}">{{ $tile['key'] }}</span>
                            <flux:icon :icon="$tile['icon']" class="size-6 text-indigo-600 dark:text-indigo-400" />
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $tile['label'] }}</p>
                        </button>
                        <flux:menu>
                            @foreach($tile['menu'] as $item)
                                <flux:menu.item :href="$item['href']" :icon="$item['icon']" wire:navigate>
                                    {{ $item['label'] }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @else
                    <a
                        href="{{ $tile['href'] }}"
                        wire:navigate
                        data-shortcut="{{ $tile['key'] }}"
                        class="{{ $tileClasses }}"
                    >
                        <span class="{{ $badgeClasses }}">{{ $tile['key'] }}</span>
                        <flux:icon :icon="$tile['icon']" class="size-6 text-indigo-600 dark:text-indigo-400" />
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $tile['label'] }}</p>
                    </a>
                @endif
            @endforeach

            @foreach($disabledTiles as $tile)
                <div
                    aria-disabled="true"
                    title="Coming soon"
                    class="relative flex cursor-not-allowed flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-200 bg-zinc-50/60 px-3 py-5 text-center opacity-60 dark:border-white/5 dark:bg-zinc-900/40"
                >
                    <flux:icon :icon="$tile['icon']" class="size-6 text-zinc-400 dark:text-zinc-600" />
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-500">{{ $tile['label'] }}</p>
                </div>
            @endforeach
        </div>
    </div>

</div>

@script
<script>
    Alpine.data('dashboardShortcuts', (keys) => ({
        keys,
        onKey(e) {
            const t = e.target;
            if (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable) return;
            if (e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) return;
            const k = e.key.toLowerCase();
            if (!this.keys.includes(k)) return;
            const el = document.querySelector(`[data-shortcut="${k}"]`);
            if (el) {
                e.preventDefault();
                e.stopPropagation();
                el.click();
            }
        },
    }));
</script>
@endscript
