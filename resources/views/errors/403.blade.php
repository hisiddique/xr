<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Access denied')])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-950">
        <img src="{{ asset('intellidb-logo.png') }}" alt="Intellidb" class="absolute left-6 top-6 h-14 w-auto">

        <div class="flex min-h-screen flex-col items-center justify-center px-6 py-10">
            <div class="w-full max-w-md text-center">
                <p class="text-sm font-semibold uppercase tracking-widest text-indigo-500 dark:text-indigo-400">403</p>
                <h1 class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">{{ __('You don’t have access to this page') }}</h1>
                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __($exception->getMessage() ?: 'Your account isn’t permitted to view this area. Contact an administrator if you think this is a mistake.') }}
                </p>

                <div class="mt-8 flex items-center justify-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500">
                            {{ __('Go to dashboard') }}
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-100 dark:border-white/15 dark:text-zinc-200 dark:hover:bg-white/5">
                                {{ __('Log out') }}
                            </button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500">
                            {{ __('Back to login') }}
                        </a>
                    @endauth
                </div>
            </div>

            <p class="mt-10 text-xs text-zinc-400 dark:text-zinc-600">&copy; {{ date('Y') }} Intellidb. All rights reserved.</p>
        </div>
    </body>
</html>
