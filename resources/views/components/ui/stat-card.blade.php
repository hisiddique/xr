@props([
    'label' => '',
    'value' => '',
    'color' => 'indigo',
    'icon' => 'chart-bar',
    'href' => null,
    'linkLabel' => null,
])

@php
    $colors = [
        'indigo'  => ['bar' => 'bg-indigo-500',  'icon' => 'bg-indigo-50 dark:bg-indigo-500/10',  'iconText' => 'text-indigo-600 dark:text-indigo-400',  'link' => 'text-indigo-600 dark:text-indigo-400'],
        'emerald' => ['bar' => 'bg-emerald-500', 'icon' => 'bg-emerald-50 dark:bg-emerald-500/10', 'iconText' => 'text-emerald-600 dark:text-emerald-400', 'link' => 'text-emerald-600 dark:text-emerald-400'],
        'amber'   => ['bar' => 'bg-amber-500',   'icon' => 'bg-amber-50 dark:bg-amber-500/10',   'iconText' => 'text-amber-600 dark:text-amber-400',   'link' => 'text-amber-600 dark:text-amber-400'],
        'violet'  => ['bar' => 'bg-violet-500',  'icon' => 'bg-violet-50 dark:bg-violet-500/10',  'iconText' => 'text-violet-600 dark:text-violet-400',  'link' => 'text-violet-600 dark:text-violet-400'],
    ];
    $c = $colors[$color] ?? $colors['indigo'];
@endphp

<div class="relative overflow-hidden rounded-2xl border border-zinc-200/70 bg-white p-6 shadow-[0_1px_2px_rgba(16,24,40,0.06),0_1px_3px_rgba(16,24,40,0.10)] dark:border-white/10 dark:bg-zinc-900">
    {{-- Accent bar --}}
    <div class="absolute inset-y-0 left-0 w-1 {{ $c['bar'] }} rounded-l-2xl"></div>

    <div class="flex items-start justify-between">
        <div class="min-w-0 flex-1 pl-2">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
            <p class="mt-2 text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $value }}</p>
            @if(isset($sublabel) && $sublabel->isNotEmpty())
                <div class="mt-1 pl-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $sublabel }}</div>
            @endif
        </div>
        <div class="ml-4 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $c['icon'] }}">
            <flux:icon :icon="$icon" class="size-5 {{ $c['iconText'] }}" />
        </div>
    </div>

    @if($href && $linkLabel)
        <div class="mt-4 pl-2">
            <a href="{{ $href }}" wire:navigate class="text-xs font-medium {{ $c['link'] }} hover:underline">
                {{ $linkLabel }} →
            </a>
        </div>
    @endif
</div>
