<?php

use App\Support\TableSorts;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Table Sorting')] class extends Component
{
    /** @var array<string, array{column: string, direction: string}> */
    public array $sorts = [];

    public function mount(): void
    {
        foreach (array_keys(TableSorts::tables()) as $table) {
            [$column, $direction] = TableSorts::for($table);
            $this->sorts[$table] = ['column' => $column, 'direction' => $direction];
        }
    }

    public function save(): void
    {
        $rules = [];

        foreach (TableSorts::tables() as $table => $definition) {
            $rules["sorts.{$table}.column"] = ['required', 'in:'.implode(',', array_keys($definition['columns']))];
            $rules["sorts.{$table}.direction"] = ['required', 'in:asc,desc'];
        }

        $this->validate($rules);

        foreach ($this->sorts as $table => $sort) {
            TableSorts::save($table, $sort['column'], $sort['direction']);
        }

        Flux::toast(variant: 'success', text: __('Default sorting saved.'));
    }

    public function resetToDefaults(): void
    {
        foreach (TableSorts::tables() as $table => $definition) {
            [$column, $direction] = $definition['default'];
            $this->sorts[$table] = ['column' => $column, 'direction' => $direction];
        }
    }

    public function with(): array
    {
        return ['tables' => TableSorts::tables()];
    }
}; ?>

<div class="flex flex-col gap-8">

    <x-ui.page-header
        title="Table Sorting"
        subtitle="Choose how each list is ordered until someone clicks a column header."
    />

    <form wire:submit="save" class="flex flex-col gap-0 overflow-hidden rounded-2xl border border-zinc-200/70 bg-white dark:border-white/10 dark:bg-zinc-900">
        <div class="grid gap-6 px-4 py-5 lg:grid-cols-[280px_1fr] lg:gap-10">
            <div>
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Default Sort</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Applies to everyone. Sorting by a column header still overrides it, and a search still lists the closest matches first.
                </p>
            </div>

            <div class="divide-y divide-zinc-200/70 dark:divide-white/10">
                @foreach ($tables as $key => $table)
                    <div class="grid gap-3 py-3 first:pt-0 last:pb-0 sm:grid-cols-[1fr_1fr_9rem] sm:items-center" wire:key="sort-{{ $key }}">
                        <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $table['label'] }}</div>

                        <flux:select wire:model="sorts.{{ $key }}.column" :aria-label="$table['label'].' sort column'">
                            @foreach ($table['columns'] as $column => $label)
                                <flux:select.option value="{{ $column }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="sorts.{{ $key }}.direction" :aria-label="$table['label'].' sort direction'">
                            <flux:select.option value="asc">Ascending</flux:select.option>
                            <flux:select.option value="desc">Descending</flux:select.option>
                        </flux:select>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-zinc-200/70 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-zinc-900/80">
            <flux:button type="button" variant="ghost" wire:click="resetToDefaults">Reset to original</flux:button>
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">Save</flux:button>
        </div>
    </form>

</div>
