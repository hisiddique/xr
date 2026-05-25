@props([
    'column',
    'align' => 'left',
    'state' => null,
])

@php
    $alignClass = match ($align) {
        'right' => 'justify-end text-right',
        'center' => 'justify-center text-center',
        default => 'justify-start text-left',
    };
@endphp

<th {{ $attributes->merge(['class' => 'px-4 py-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 '.$alignClass]) }}>
    <button
        type="button"
        wire:click="sortBy('{{ $column }}')"
        class="group inline-flex items-center gap-1.5 rounded-md px-1 -mx-1 py-0.5 hover:bg-zinc-100 dark:hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/40 transition-colors {{ $alignClass }}"
    >
        <span>{{ $slot }}</span>
        @if($state === 'asc')
            <flux:icon.chevron-up class="size-3.5 text-indigo-500" />
        @elseif($state === 'desc')
            <flux:icon.chevron-down class="size-3.5 text-indigo-500" />
        @else
            <flux:icon.chevron-up-down class="size-3.5 text-zinc-300 group-hover:text-zinc-400 dark:text-zinc-600 dark:group-hover:text-zinc-500" />
        @endif
    </button>
</th>
