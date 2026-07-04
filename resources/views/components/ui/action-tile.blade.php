@props([
    'label',
    'icon',
    'color' => 'blue',
    'href' => null,
    'modal' => null,
    'disabled' => false,
    'badge' => null,
])

@php
    $colors = [
        'blue'    => ['text' => 'text-blue-600 dark:text-blue-400',       'hover' => 'hover:bg-blue-50 hover:border-blue-200 dark:hover:bg-blue-500/10 dark:hover:border-blue-500/40',       'badge' => 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-300'],
        'emerald' => ['text' => 'text-emerald-600 dark:text-emerald-400', 'hover' => 'hover:bg-emerald-50 hover:border-emerald-200 dark:hover:bg-emerald-500/10 dark:hover:border-emerald-500/40', 'badge' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300'],
        'amber'   => ['text' => 'text-amber-600 dark:text-amber-400',     'hover' => 'hover:bg-amber-50 hover:border-amber-200 dark:hover:bg-amber-500/10 dark:hover:border-amber-500/40',     'badge' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300'],
        'slate'   => ['text' => 'text-slate-600 dark:text-slate-400',     'hover' => 'hover:bg-slate-100 hover:border-slate-300 dark:hover:bg-slate-500/10 dark:hover:border-slate-500/40',     'badge' => 'bg-slate-200 text-slate-800 dark:bg-slate-500/20 dark:text-slate-300'],
        'violet'  => ['text' => 'text-violet-500 dark:text-violet-400',   'hover' => 'hover:bg-violet-50 hover:border-violet-200 dark:hover:bg-violet-500/10 dark:hover:border-violet-500/40',   'badge' => 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-300'],
    ];
    $c = $colors[$color] ?? $colors['blue'];
    $base = 'group relative flex flex-col items-start gap-3 rounded-lg border p-4 text-left transition-all';
    $surface = $badge
        ? 'border-dashed border-zinc-300 bg-transparent dark:border-white/15'
        : 'border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-zinc-800/40';
@endphp

@if($disabled)
    <div aria-disabled="true" title="Coming soon"
        class="{{ $base }} cursor-not-allowed border-dashed border-zinc-200 bg-zinc-50/60 opacity-60 dark:border-white/5 dark:bg-zinc-900/40">
        <flux:icon :icon="$icon" class="size-5 text-zinc-400 dark:text-zinc-600" />
        <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-500">{{ $label }}</p>
    </div>
@elseif($modal)
    <button type="button" x-on:click="$flux.modal('{{ $modal }}').show()"
        class="{{ $base }} {{ $surface }} {{ $c['hover'] }} w-full cursor-pointer hover:-translate-y-0.5">
        @if($badge)
            <span class="absolute right-3 top-3 rounded px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide {{ $c['badge'] }}">{{ $badge }}</span>
        @endif
        <flux:icon :icon="$icon" class="size-5 {{ $c['text'] }}" />
        <p class="text-sm font-semibold text-slate-700 dark:text-white">{{ $label }}</p>
    </button>
@else
    <a href="{{ $href }}" wire:navigate
        class="{{ $base }} {{ $surface }} {{ $c['hover'] }} hover:-translate-y-0.5">
        @if($badge)
            <span class="absolute right-3 top-3 rounded px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide {{ $c['badge'] }}">{{ $badge }}</span>
        @endif
        <flux:icon :icon="$icon" class="size-5 {{ $c['text'] }}" />
        <p class="text-sm font-semibold text-slate-700 dark:text-white">{{ $label }}</p>
    </a>
@endif
