@props([
    'column',
    'align' => 'left',
    'state' => null,
    'tone' => 'default',
])

@php
    $alignClass = match ($align) {
        'right' => 'justify-end text-right',
        'center' => 'justify-center text-center',
        default => 'justify-start text-left',
    };

    $onDark = $tone === 'onDark';

    $thClass = $onDark
        ? 'px-4 py-1 text-xs font-bold uppercase tracking-wider text-white '.$alignClass
        : 'px-4 py-1 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 '.$alignClass;

    $buttonClass = $onDark
        ? 'hover:bg-white/15 focus-visible:ring-white/50'
        : 'hover:bg-zinc-100 dark:hover:bg-zinc-800 focus-visible:ring-indigo-500/40';

    $activeIconClass = $onDark ? 'text-white' : 'text-indigo-500';
    $idleIconClass = $onDark
        ? 'text-white/50 group-hover:text-white/80'
        : 'text-zinc-300 group-hover:text-zinc-400 dark:text-zinc-600 dark:group-hover:text-zinc-500';
@endphp

<th {{ $attributes->merge(['class' => $thClass]) }}>
    <button
        type="button"
        wire:click="sortBy('{{ $column }}')"
        class="group inline-flex items-center gap-1.5 rounded-md px-1 -mx-1 py-0.5 focus:outline-none focus-visible:ring-2 transition-colors {{ $buttonClass }} {{ $alignClass }}"
    >
        <span>{{ $slot }}</span>
        @if($state === 'asc')
            <flux:icon.chevron-up class="size-3.5 {{ $activeIconClass }}" />
        @elseif($state === 'desc')
            <flux:icon.chevron-down class="size-3.5 {{ $activeIconClass }}" />
        @else
            <flux:icon.chevron-up-down class="size-3.5 {{ $idleIconClass }}" />
        @endif
    </button>
</th>
