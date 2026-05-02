<?php

use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Modelable;
use Livewire\Component;

new class extends Component {
    #[Modelable]
    public mixed $value = null;

    public string $model = '';
    public string $column = 'name';
    public string $valueColumn = 'id';
    public string $label = '';
    public string $placeholder = '';
    public string $errorName = '';
    public int $minChars = 3;
    public int $cacheTtl = 60;
    public int $maxResults = 10;
    public bool $required = false;

    public string $search = '';
    public string $selectedLabel = '';

    public function mount(string $selectedLabel = ''): void
    {
        $this->selectedLabel = $selectedLabel;
    }

    public function selectOption(int|string $id, string $label): void
    {
        $this->value = $id;
        $this->selectedLabel = $label;
        $this->search = '';
    }

    public function clearSelection(): void
    {
        $this->value = null;
        $this->selectedLabel = '';
        $this->search = '';
    }

    #[Computed]
    public function suggestions(): array
    {
        $term = trim($this->search);

        if (mb_strlen($term) < $this->minChars) {
            return [];
        }

        $modelClass = $this->model;
        $column = $this->column;
        $valueColumn = $this->valueColumn;
        $limit = $this->maxResults;

        $cacheKey = 'typeahead:'.md5($modelClass.'|'.$column.'|'.$valueColumn.'|'.$limit.'|'.mb_strtolower($term));

        return Cache::remember(
            $cacheKey,
            $this->cacheTtl,
            fn (): array => $modelClass::query()
                ->where($column, 'like', "%{$term}%")
                ->orderBy($column)
                ->limit($limit)
                ->get([$valueColumn, $column])
                ->map(fn ($m): array => [
                    'id' => $m->{$valueColumn},
                    'label' => (string) $m->{$column},
                ])
                ->all(),
        );
    }
}; ?>

<div x-data="{ open: false }" x-on:click.outside="open = false">
    @if($label !== '')
        <flux:label>
            {{ $label }}
            @if($required)<span class="text-rose-500">*</span>@endif
        </flux:label>
    @endif

    <div class="relative">
        @if($value && $selectedLabel !== '')
            <div class="flex items-center justify-between rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-zinc-800">
                <span class="font-medium text-zinc-900 dark:text-white">{{ $selectedLabel }}</span>
                <flux:button size="xs" variant="ghost" icon="x-mark" type="button" wire:click="clearSelection" />
            </div>
        @else
            <flux:input
                wire:model.live.debounce.300ms="search"
                x-on:focus="open = true"
                x-on:input="open = true"
                icon="magnifying-glass"
                :placeholder="$placeholder !== '' ? $placeholder : __('Type at least :n letters…', ['n' => $minChars])"
                autocomplete="off"
            />

            @if(mb_strlen(trim($search)) >= $minChars)
                <div
                    x-show="open"
                    x-cloak
                    x-transition.opacity
                    class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-zinc-200 bg-white shadow-lg dark:border-white/10 dark:bg-zinc-900"
                >
                    @if(empty($this->suggestions))
                        <div class="px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('No matches.') }}
                        </div>
                    @else
                        @foreach($this->suggestions as $opt)
                            <button
                                type="button"
                                wire:click="selectOption({{ json_encode($opt['id']) }}, @js($opt['label']))"
                                x-on:click="open = false"
                                class="block w-full cursor-pointer px-3 py-2 text-left text-sm text-zinc-900 transition-colors hover:bg-indigo-50 hover:text-indigo-700 dark:text-white dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300"
                            >
                                {{ $opt['label'] }}
                            </button>
                        @endforeach
                    @endif
                </div>
            @endif
        @endif

        @if($errorName !== '')
            <flux:error :name="$errorName" />
        @endif
    </div>
</div>
