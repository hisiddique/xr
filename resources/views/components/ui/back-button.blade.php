@props([
    'fallback' => null,
    'hintKey' => 'Esc',
])

<flux:button
    type="button"
    {{ $attributes->merge(['variant' => 'ghost']) }}
    x-data
    x-on:click.prevent="window.history.length > 1 ? window.history.back() : Livewire.navigate({{ \Illuminate\Support\Js::from($fallback) }})"
>
    {{ $slot->isEmpty() ? __('Cancel') : $slot }}
    @if($hintKey)<x-ui.kbd-hint :keys="$hintKey" />@endif
</flux:button>
