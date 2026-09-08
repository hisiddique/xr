@props([
    'name',
    'selected' => [],
    'options' => [],
    'label' => null,
    'placeholder' => 'Select…',
])

@php
    $optionList = collect($options)->map(fn ($o) => [
        'value' => (string) ($o['value'] ?? $o->value),
        'label' => (string) ($o['label'] ?? $o->label),
    ])->values()->all();
    $selectedList = collect($selected)->map(fn ($v) => (string) $v)->values()->all();
@endphp

<div
    x-data="multiSelect($wire, @js($selectedList), '{{ $name }}', @js($optionList))"
    x-on:click.outside="open = false"
    x-on:keydown.escape="open = false"
    class="relative"
>
    @if($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    <div
        x-on:click="open = ! open"
        class="mt-1 flex min-h-[38px] w-full cursor-pointer flex-wrap items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 text-sm focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500 dark:border-white/15 dark:bg-zinc-800"
    >
        <template x-if="selected.length === 0">
            <span class="text-zinc-400 dark:text-zinc-500">{{ $placeholder }}</span>
        </template>

        <template x-for="value in selected" :key="value">
            <span class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-500/20 dark:text-indigo-300 dark:ring-indigo-500/30">
                <span x-text="labelFor(value)"></span>
                <button type="button" x-on:click.stop="toggle(value)" class="flex items-center text-indigo-400 hover:text-indigo-600 dark:hover:text-indigo-200">
                    <svg class="size-3" viewBox="0 0 12 12" fill="none"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </button>
            </span>
        </template>

        <svg class="ms-auto size-4 shrink-0 text-zinc-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
    </div>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-white/10 dark:bg-zinc-900"
    >
        <div class="border-b border-zinc-100 p-2 dark:border-white/[0.06]">
            <input
                type="search"
                x-model="search"
                placeholder="{{ __('Search…') }}"
                class="w-full rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-sm text-zinc-900 placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/15 dark:bg-zinc-800 dark:text-white"
            />
        </div>

        <div class="max-h-56 overflow-y-auto py-1">
            <template x-for="opt in filtered" :key="opt.value">
                <button
                    type="button"
                    x-on:click="toggle(opt.value)"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-zinc-900 hover:bg-indigo-50 hover:text-indigo-700 dark:text-white dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300"
                >
                    <span class="flex size-4 shrink-0 items-center justify-center rounded border border-zinc-300 dark:border-white/20" :class="isSelected(opt.value) && 'border-indigo-600 bg-indigo-600 text-white dark:border-indigo-500 dark:bg-indigo-500'">
                        <svg x-show="isSelected(opt.value)" class="size-3" viewBox="0 0 12 12" fill="none"><path d="M2.5 6.5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <span x-text="opt.label"></span>
                </button>
            </template>
            <p x-show="filtered.length === 0" class="px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No matches.') }}</p>
        </div>
    </div>

    @if($name)
        <flux:error :name="$name" />
        <flux:error :name="$name.'.*'" />
    @endif
</div>
