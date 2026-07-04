@props([
    'label',
    'icon',
    'color' => 'blue',
])

@php
    $colors = [
        'blue'    => 'text-blue-600 dark:text-blue-400',
        'emerald' => 'text-emerald-600 dark:text-emerald-400',
        'amber'   => 'text-amber-600 dark:text-amber-400',
        'slate'   => 'text-slate-600 dark:text-slate-400',
        'violet'  => 'text-violet-500 dark:text-violet-400',
    ];
    $iconText = $colors[$color] ?? $colors['blue'];
@endphp

<div class="rounded-2xl border border-zinc-200/70 bg-white p-6 dark:border-white/10 dark:bg-zinc-900">
    <div class="mb-5 flex items-center gap-2.5 border-b border-zinc-200/70 pb-3.5 dark:border-white/10">
        <flux:icon :icon="$icon" class="size-5 {{ $iconText }}" />
        <h2 class="text-base font-semibold {{ $iconText }}">{{ $label }}</h2>
    </div>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        {{ $slot }}
    </div>
</div>
