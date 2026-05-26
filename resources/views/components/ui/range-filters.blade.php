@props([
    'dateFrom' => '',
    'dateTo' => '',
    'amountMin' => '',
    'amountMax' => '',
    'currency' => '£',
])

@php
    $hasAny = $dateFrom !== '' || $dateTo !== '' || $amountMin !== '' || $amountMax !== '';
@endphp

<div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
    <div class="flex flex-col">
        <label class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('From') }}</label>
        <flux:input wire:model.live.debounce.400ms="dateFrom" type="date" size="sm" class="!w-40" />
    </div>
    <div class="flex flex-col">
        <label class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('To') }}</label>
        <flux:input wire:model.live.debounce.400ms="dateTo" type="date" size="sm" class="!w-40" />
    </div>
    <div class="flex flex-col">
        <label class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('Min :cur', ['cur' => $currency]) }}</label>
        <flux:input wire:model.live.debounce.400ms="amountMin" type="number" step="0.01" min="0" size="sm" placeholder="0.00" class="!w-28" />
    </div>
    <div class="flex flex-col">
        <label class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('Max :cur', ['cur' => $currency]) }}</label>
        <flux:input wire:model.live.debounce.400ms="amountMax" type="number" step="0.01" min="0" size="sm" placeholder="0.00" class="!w-28" />
    </div>
    @if($hasAny)
        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear') }}</flux:button>
    @endif
</div>
