<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 dark:bg-zinc-950 antialiased" data-is-admin="{{ auth()->user()?->isAdmin() ? '1' : '0' }}">

        {{-- ===================== MOBILE TOPBAR ===================== --}}
        <header class="sticky top-0 z-40 flex h-14 items-center gap-3 border-b border-zinc-200/70 bg-white px-4 dark:border-white/10 dark:bg-zinc-900 lg:hidden">
            <button
                type="button"
                x-data
                x-on:click="$dispatch('open-sidebar')"
                class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/5"
                aria-label="Open navigation"
            >
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                </svg>
            </button>

            {{-- Logo wordmark --}}
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
                <svg width="24" height="24" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect width="32" height="32" rx="10" fill="url(#mob-split)" />
                    <defs>
                        <linearGradient id="mob-split" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                            <stop offset="0.48" stop-color="#4f46e5" />
                            <stop offset="0.52" stop-color="#a855f7" />
                        </linearGradient>
                    </defs>
                    <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" fill="white" font-size="16" font-family="sans-serif" font-weight="700">D</text>
                </svg>
                <span class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-white">DeliveryCRM</span>
            </a>

            <div class="ml-auto flex items-center gap-1.5">
                <flux:button size="sm" variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>
                    Customer <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1 rounded bg-white/20 px-1 py-0.5 text-[10px] font-mono">F1</kbd>
                </flux:button>
                <flux:button size="sm" variant="primary" icon="plus" :href="route('delivery-notes.create')" wire:navigate>
                    DN <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1 rounded bg-white/20 px-1 py-0.5 text-[10px] font-mono">F2</kbd>
                </flux:button>
            </div>
        </header>

        {{-- ===================== LAYOUT WRAPPER ===================== --}}
        <div class="flex min-h-screen lg:min-h-0">

            {{-- ===================== SIDEBAR ===================== --}}
            <aside
                x-data="{ open: false }"
                x-on:open-sidebar.window="open = true"
                x-on:keydown.escape.window="open = false"
                class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-white dark:bg-zinc-900 border-r border-zinc-200/70 dark:border-white/10 shadow-xl transition-transform duration-200 ease-in-out lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:translate-x-0 lg:shadow-none"
                :class="open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
            >
                {{-- Backdrop (mobile only) --}}
                <div
                    x-cloak
                    x-show="open"
                    x-on:click="open = false"
                    class="fixed inset-0 z-[-1] bg-zinc-950/50 lg:hidden"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                ></div>

                {{-- Header --}}
                <div class="flex h-16 shrink-0 items-center gap-3 border-b border-zinc-200/70 px-5 dark:border-white/10">
                    {{-- Brand logo: diagonal split SVG --}}
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-3 group">
                        <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="shrink-0">
                            <rect width="36" height="36" rx="10" fill="url(#sidebar-logo-bg)" />
                            <defs>
                                <linearGradient id="sidebar-logo-bg" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                                    <stop offset="0.48" stop-color="#4f46e5" />
                                    <stop offset="0.52" stop-color="#a855f7" />
                                </linearGradient>
                            </defs>
                            <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" fill="white" font-size="18" font-family="sans-serif" font-weight="700">D</text>
                        </svg>
                        <div>
                            <p class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors">DeliveryCRM</p>
                            <p class="text-[10px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Workspace</p>
                        </div>
                    </a>

                    {{-- Close on mobile --}}
                    <button
                        type="button"
                        x-on:click="open = false"
                        class="ml-auto rounded-lg p-1 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 lg:hidden"
                        aria-label="Close sidebar"
                    >
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {{-- Nav --}}
                <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6">
                    {{-- MAIN group --}}
                    <div>
                        <p class="mb-2 px-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Main</p>
                        <ul class="space-y-0.5">
                            <li>
                                <a
                                    href="{{ route('dashboard') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('dashboard'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('dashboard'),
                                    ])
                                >
                                    <flux:icon.home class="size-5 shrink-0" />
                                    <span class="flex-1">Dashboard</span>
                                    <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-auto rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">F3</kbd>
                                </a>
                            </li>
                            <li>
                                <a
                                    href="{{ route('customers.index') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('customers.*'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('customers.*'),
                                    ])
                                >
                                    <flux:icon.users class="size-5 shrink-0" />
                                    <span class="flex-1">Customers</span>
                                    <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-auto rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">F4</kbd>
                                </a>
                            </li>
                            <li>
                                <a
                                    href="{{ route('delivery-notes.index') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('delivery-notes.*'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('delivery-notes.*'),
                                    ])
                                >
                                    <flux:icon.truck class="size-5 shrink-0" />
                                    <span class="flex-1">Delivery Notes</span>
                                    <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-auto rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">F5</kbd>
                                </a>
                            </li>
                            <li>
                                <a
                                    href="{{ route('invoices.index') }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('invoices.*'),
                                        'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('invoices.*'),
                                    ])
                                >
                                    <flux:icon.document-text class="size-5 shrink-0" />
                                    <span class="flex-1">Invoices</span>
                                    <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-auto rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">F6</kbd>
                                </a>
                            </li>
                        </ul>
                    </div>

                    @if(auth()->user()?->isAdmin())
                        {{-- ADMIN group --}}
                        <div>
                            <p class="mb-2 px-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Admin</p>
                            <ul class="space-y-0.5">
                                <li x-data="{ open: {{ request()->routeIs('reference-data.*') ? 'true' : 'false' }} }">
                                    <button
                                        type="button"
                                        x-on:click="open = !open"
                                        @class([
                                            'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                            'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.*'),
                                            'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.*'),
                                        ])
                                    >
                                        <flux:icon.tag class="size-5 shrink-0" />
                                        <span class="flex-1 text-left">Reference Data</span>
                                        <svg
                                            class="size-4 shrink-0 transition-transform duration-150"
                                            :class="open ? 'rotate-180' : ''"
                                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </button>
                                    <ul x-show="open" x-collapse class="mt-0.5 space-y-0.5">
                                        <li>
                                            <a
                                                href="{{ route('reference-data.titles') }}"
                                                wire:navigate
                                                @class([
                                                    'flex items-center gap-3 rounded-lg py-2 pr-3 pl-9 text-sm font-medium transition-colors',
                                                    'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.titles'),
                                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.titles'),
                                                ])
                                            >
                                                Titles
                                            </a>
                                        </li>
                                        <li>
                                            <a
                                                href="{{ route('reference-data.credit-terms') }}"
                                                wire:navigate
                                                @class([
                                                    'flex items-center gap-3 rounded-lg py-2 pr-3 pl-9 text-sm font-medium transition-colors',
                                                    'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.credit-terms'),
                                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.credit-terms'),
                                                ])
                                            >
                                                Credit Terms
                                            </a>
                                        </li>
                                        <li>
                                            <a
                                                href="{{ route('reference-data.credit-limits') }}"
                                                wire:navigate
                                                @class([
                                                    'flex items-center gap-3 rounded-lg py-2 pr-3 pl-9 text-sm font-medium transition-colors',
                                                    'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.credit-limits'),
                                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.credit-limits'),
                                                ])
                                            >
                                                Credit Limits
                                            </a>
                                        </li>
                                        <li>
                                            <a
                                                href="{{ route('reference-data.units') }}"
                                                wire:navigate
                                                @class([
                                                    'flex items-center gap-3 rounded-lg py-2 pr-3 pl-9 text-sm font-medium transition-colors',
                                                    'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('reference-data.units'),
                                                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('reference-data.units'),
                                                ])
                                            >
                                                Units
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                                <li>
                                    <a
                                        href="{{ route('settings.crm') }}"
                                        wire:navigate
                                        @class([
                                            'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                            'bg-indigo-50 text-indigo-700 border-l-[3px] border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('settings.crm'),
                                            'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => !request()->routeIs('settings.crm'),
                                        ])
                                    >
                                        <flux:icon.cog-6-tooth class="size-5 shrink-0" />
                                        <span class="flex-1">CRM Settings</span>
                                        <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-auto rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">F11</kbd>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    @endif
                </nav>

                {{-- Footer: user card --}}
                <div class="border-t border-zinc-200/70 p-3 dark:border-white/10">
                    <flux:dropdown position="top" align="start" class="w-full">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-zinc-100 dark:hover:bg-white/5"
                            data-test="sidebar-menu-button"
                        >
                            <x-ui.avatar :name="auth()->user()->name" size="sm" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ auth()->user()->name }}</p>
                                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ auth()->user()->email }}</p>
                            </div>
                            <svg class="size-4 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>

                        <flux:menu>
                            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                {{ __('Settings') }}
                            </flux:menu.item>
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
                </div>
            </aside>

            {{-- ===================== MAIN CONTENT ===================== --}}
            <div class="flex min-w-0 flex-1 flex-col">

                {{-- Topbar (desktop) --}}
                <div class="sticky top-0 z-30 hidden h-16 items-center gap-4 border-b border-zinc-200/70 bg-white px-6 dark:border-white/10 dark:bg-zinc-950 lg:flex">
                    {{-- Breadcrumb --}}
                    <nav class="flex items-center gap-1.5 text-sm" aria-label="Breadcrumb">
                        <a href="{{ route('dashboard') }}" wire:navigate class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                            Home
                        </a>
                        @php
                            $routeName = request()->route()?->getName() ?? '';
                            $crumbSegments = collect(explode('.', $routeName))
                                ->filter(fn($s) => !in_array($s, ['index', '']));
                        @endphp
                        @foreach($crumbSegments as $crumbSegment)
                            <svg class="size-3.5 text-zinc-300 dark:text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                            <span class="font-medium capitalize text-zinc-700 dark:text-zinc-300">{{ str_replace('-', ' ', $crumbSegment) }}</span>
                        @endforeach
                    </nav>

                    <div class="ml-auto flex items-center gap-2">
                        {{-- Search pill (UI only) --}}
                        <div class="relative hidden xl:block">
                            <svg class="absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                            <input
                                type="text"
                                placeholder="Search customers, notes…"
                                class="h-8 w-64 rounded-full border border-zinc-200 bg-zinc-50 pl-8 pr-4 text-xs text-zinc-700 placeholder-zinc-400 transition focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-zinc-800 dark:text-zinc-300"
                                aria-label="Search"
                            />
                        </div>

                        {{-- + New actions --}}
                        <flux:button size="sm" variant="primary" icon="plus" :href="route('customers.create')" wire:navigate class="rounded-lg">
                            Customer <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1 rounded bg-white/20 px-1 py-0.5 text-[10px] font-mono">F1</kbd>
                        </flux:button>
                        <flux:button size="sm" variant="primary" icon="plus" :href="route('delivery-notes.create')" wire:navigate class="rounded-lg">
                            DN <kbd x-show="$store.hotkeys.showLabels" x-cloak class="ml-1 rounded bg-white/20 px-1 py-0.5 text-[10px] font-mono">F2</kbd>
                        </flux:button>

                        {{-- Help --}}
                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="question-mark-circle"
                            x-on:click="$flux.modal('hotkeys-help').show()"
                            :title="__('Keyboard shortcuts (?)')"
                        />
                    </div>
                </div>

                {{-- Page content --}}
                <main class="min-h-[calc(100vh-4rem)] bg-zinc-50 dark:bg-zinc-950">
                    <div class="mx-auto max-w-7xl px-6 py-8">

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
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        {{-- Hidden logout form for F12 shortcut --}}
        <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>

        @fluxScripts
    </body>
</html>
