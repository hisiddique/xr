@props([
    'model',
])

<flux:select
    wire:model.live="{{ $model }}"
    size="sm"
    {{ $attributes->merge(['class' => 'w-full min-w-0']) }}
>
    {{ $slot }}
</flux:select>
