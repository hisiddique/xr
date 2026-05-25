@props([
    'fallback' => null,
    'hintKey' => 'Esc',
    'confirm' => false,
])
@php
    $fallbackJs = \Illuminate\Support\Js::from($fallback);
    $backJs = "window.history.length > 1 ? window.history.back() : Livewire.navigate({$fallbackJs})";
    $clickJs = $confirm
        ? "typeof confirmExit === 'function' ? confirmExit() : ({$backJs})"
        : $backJs;
@endphp

<flux:button
    type="button"
    {{ $attributes->merge(['variant' => 'ghost']) }}
    x-data
    x-on:click.prevent="{!! $clickJs !!}"
>
    {{ $slot->isEmpty() ? __('Cancel') : $slot }}
    @if($hintKey)<x-ui.kbd-hint :keys="$hintKey" />@endif
</flux:button>
