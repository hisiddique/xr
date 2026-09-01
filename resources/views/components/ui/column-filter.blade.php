@props([
    'model',
    'placeholder' => '',
])

<flux:input
    wire:model.live.debounce.300ms="{{ $model }}"
    type="search"
    size="sm"
    autocomplete="off"
    :placeholder="$placeholder"
    clearable
    {{ $attributes->merge(['class' => 'w-full min-w-0']) }}
/>
