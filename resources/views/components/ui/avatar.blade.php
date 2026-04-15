@props([
    'name' => '',
    'size' => 'md',
])

@php
    $initials = collect(explode(' ', trim($name)))
        ->map(fn($w) => strtoupper(substr($w, 0, 1)))
        ->take(2)
        ->implode('');

    $sizes = [
        'xs' => 'size-6 text-[10px]',
        'sm' => 'size-8 text-xs',
        'md' => 'size-10 text-sm',
        'lg' => 'size-14 text-lg',
        'xl' => 'size-20 text-2xl',
    ];

    $cls = $sizes[$size] ?? $sizes['md'];
@endphp

<span
    {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-fuchsia-500 font-semibold text-white select-none {$cls}"]) }}
    aria-hidden="true"
>
    {{ $initials ?: '?' }}
</span>
