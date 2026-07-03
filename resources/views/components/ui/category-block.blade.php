@props([
    'label',
    'icon',
    'color' => 'indigo',
])

@php
    $colors = [
        'indigo'  => 'text-indigo-600 dark:text-indigo-400',
        'emerald' => 'text-emerald-600 dark:text-emerald-400',
        'amber'   => 'text-amber-600 dark:text-amber-400',
        'slate'   => 'text-slate-600 dark:text-slate-400',
        'violet'  => 'text-violet-600 dark:text-violet-400',
    ];
    $iconText = $colors[$color] ?? $colors['indigo'];
@endphp

<div class="rounded-2xl border border-zinc-200/70 bg-white p-5 dark:border-white/10 dark:bg-zinc-900">
    <div class="mb-4 flex items-center gap-2 border-b border-zinc-200/70 pb-3 dark:border-white/10">
        <flux:icon :icon="$icon" class="size-5 {{ $iconText }}" />
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $label }}</h2>
    </div>
    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-4">
        {{ $slot }}
    </div>
</div>
