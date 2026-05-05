@props([
    'keys' => '',
])

<kbd
    x-show="$store.hotkeys.showLabels"
    x-cloak
    {{ $attributes->merge(['class' => 'ml-1.5 rounded border border-zinc-200 bg-zinc-100 px-1 py-0.5 text-[10px] font-mono text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400']) }}
>{{ $keys }}</kbd>
