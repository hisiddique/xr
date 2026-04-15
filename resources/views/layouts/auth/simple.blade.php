<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-950">
        <div class="flex min-h-screen">

            {{-- Left brand panel (hidden on < lg) --}}
            <div class="relative hidden lg:flex lg:w-[45%] flex-col overflow-hidden bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600">
                {{-- Noise / dot pattern --}}
                <div class="pointer-events-none absolute inset-0 opacity-[0.07]">
                    <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <pattern id="auth-noise" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">
                                <circle cx="2" cy="2" r="1.2" fill="white" />
                            </pattern>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#auth-noise)" />
                    </svg>
                </div>

                {{-- Top logo + wordmark --}}
                <div class="relative z-10 flex items-center gap-3 p-10">
                    <svg width="44" height="44" viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect width="44" height="44" rx="12" fill="rgba(255,255,255,0.2)" />
                        <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" fill="white" font-size="24" font-family="sans-serif" font-weight="700">D</text>
                    </svg>
                    <span class="text-lg font-semibold tracking-tight text-white">DeliveryCRM</span>
                </div>

                {{-- Center: pull-quote --}}
                <div class="relative z-10 flex flex-1 flex-col items-start justify-center px-10 pb-10">
                    <h2 class="text-4xl font-semibold tracking-tight leading-tight text-white">
                        Ship faster.<br>Invoice smarter.
                    </h2>
                    <p class="mt-4 max-w-xs text-base leading-relaxed text-indigo-100">
                        Manage your deliveries, customers, and invoices — all in one beautifully simple place.
                    </p>

                    {{-- Feature pills --}}
                    <div class="mt-8 flex flex-wrap gap-2">
                        @foreach(['Delivery Notes', 'Invoice Conversion', 'Customer CRM', 'Smart Lookups'] as $feature)
                            <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm">
                                {{ $feature }}
                            </span>
                        @endforeach
                    </div>
                </div>

                {{-- Bottom copyright --}}
                <div class="relative z-10 px-10 pb-8">
                    <p class="text-xs text-indigo-200/70">&copy; {{ date('Y') }} DeliveryCRM. All rights reserved.</p>
                </div>
            </div>

            {{-- Right form panel --}}
            <div class="flex w-full flex-col items-center justify-center bg-white px-6 py-10 dark:bg-zinc-950 lg:w-[55%] lg:px-16">
                {{-- Mobile logo (shown only < lg) --}}
                <div class="mb-8 flex flex-col items-center gap-2 lg:hidden">
                    <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <rect width="40" height="40" rx="10" fill="url(#auth-mobile-logo)" />
                        <defs>
                            <linearGradient id="auth-mobile-logo" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
                                <stop offset="0.48" stop-color="#4f46e5" />
                                <stop offset="0.52" stop-color="#a855f7" />
                            </linearGradient>
                        </defs>
                        <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" fill="white" font-size="20" font-family="sans-serif" font-weight="700">D</text>
                    </svg>
                    <span class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">DeliveryCRM</span>
                </div>

                <div class="w-full max-w-sm">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
