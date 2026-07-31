<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-950">
        <img src="{{ asset('intellidb-logo.png') }}" alt="Intellidb" class="absolute left-6 top-6 h-14 w-auto">

        <div class="flex min-h-screen flex-col items-center justify-center px-6 py-10">
            <div class="w-full max-w-sm">
                {{ $slot }}
            </div>

            <p class="mt-10 text-xs text-zinc-400 dark:text-zinc-600">&copy; {{ date('Y') }} Intellidb. All rights reserved.</p>
        </div>

        @persist('toast')
            <flux:toast.group position="middle center" class="flex flex-col gap-2">
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
