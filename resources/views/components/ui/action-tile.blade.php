@props([
    'label',
    'icon',
    'color' => 'indigo',
    'href' => null,
    'modal' => null,
    'disabled' => false,
    'badge' => null,
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
    $base = 'group relative flex items-center gap-2.5 rounded-xl border px-3.5 py-3 text-left transition';
@endphp

@if($disabled)
    <div aria-disabled="true" title="Coming soon"
        class="{{ $base }} cursor-not-allowed border-dashed border-zinc-200 bg-zinc-50/60 opacity-60 dark:border-white/5 dark:bg-zinc-900/40">
        <flux:icon :icon="$icon" class="size-5 shrink-0 text-zinc-400 dark:text-zinc-600" />
        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-500">{{ $label }}</p>
    </div>
@elseif($modal)
    <button type="button" x-on:click="$flux.modal('{{ $modal }}').show()"
        class="{{ $base }} w-full cursor-pointer border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06)] hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:bg-zinc-900">
        <flux:icon :icon="$icon" class="size-5 shrink-0 {{ $iconText }}" />
        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $label }}</p>
        @if($badge)
            <span class="absolute right-2 top-2 rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide {{ $iconText }} bg-current/10">{{ $badge }}</span>
        @endif
    </button>
@else
    <a href="{{ $href }}" wire:navigate
        class="{{ $base }} border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.06)] hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:bg-zinc-900">
        <flux:icon :icon="$icon" class="size-5 shrink-0 {{ $iconText }}" />
        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $label }}</p>
        @if($badge)
            <span class="absolute right-2 top-2 rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide {{ $iconText }} bg-current/10">{{ $badge }}</span>
        @endif
    </a>
@endif
