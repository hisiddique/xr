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
    /** @var array<string> */
    public array $searchColumns = [];
    public string $labelAccessor = '';

    public string $search = '';
    public string $selectedLabel = '';

    public function mount(string $selectedLabel = '', int $minChars = 3): void
    {
        $this->selectedLabel = $selectedLabel;
        $this->minChars = $minChars;
    }

    public function selectOption(int|string $id, string $label): void
    {
        $this->value = $id;
        $this->selectedLabel = $label;
        $this->search = '';
        $this->dispatch('typeahead-selected');
    }

    public function clearSelection(): void
    {
        $this->value = null;
        $this->selectedLabel = '';
        $this->search = '';
        $this->dispatch('typeahead-cleared');
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
        $searchColumns = $this->searchColumns ?: [$column];
        $labelAccessor = $this->labelAccessor ?: $column;
        $useAccessor = $this->labelAccessor !== '';

        $cacheKey = 'typeahead:'.md5($modelClass.'|'.$column.'|'.$valueColumn.'|'.$limit.'|'.implode(',', $searchColumns).'|'.$labelAccessor.'|'.mb_strtolower($term));

        return Cache::remember(
            $cacheKey,
            $this->cacheTtl,
            function () use ($modelClass, $column, $valueColumn, $searchColumns, $labelAccessor, $useAccessor, $limit, $term): array {
                $query = $modelClass::query()
                    ->where(function ($q) use ($searchColumns, $term): void {
                        foreach ($searchColumns as $col) {
                            $q->orWhere($col, 'like', "%{$term}%");
                        }
                    })
                    ->orderByRaw("CASE WHEN {$column} LIKE ? THEN 0 ELSE 1 END", [$term.'%'])
                    ->orderBy($column)
                    ->limit($limit);

                $results = $useAccessor ? $query->get() : $query->get([$valueColumn, $column]);

                return $results->map(fn ($m): array => [
                    'id' => $m->{$valueColumn},
                    'label' => (string) $m->{$labelAccessor},
                ])->all();
            },
        );
    }
}; ?>

<div
    x-data="{
        open: false,
        focusResult(dir) {
            const opts = Array.from(this.$el.querySelectorAll('[data-typeahead-option]'));
            if (! opts.length) return false;
            const i = opts.indexOf(document.activeElement);
            let next;
            if (i === -1) {
                next = dir > 0 ? opts[0] : opts[opts.length - 1];
            } else {
                next = opts[i + dir];
            }
            if (next) { next.focus(); return true; }
            return false;
        },
    }"
    x-on:click.outside="open = false"
    x-on:typeahead-cleared="$nextTick(() => $el.querySelector('input')?.focus())"
    x-on:typeahead-selected="$nextTick(() => { if (! window.focusNextFormField?.($el)) $el.querySelector('[data-form-stop]')?.focus(); })"
    x-on:keydown="
        if (! open) return;
        if ($event.key === 'ArrowDown') {
            if (focusResult(1)) { $event.preventDefault(); $event.stopPropagation(); }
        } else if ($event.key === 'ArrowUp') {
            const opts = Array.from($el.querySelectorAll('[data-typeahead-option]'));
            const i = opts.indexOf(document.activeElement);
            if (i === 0) {
                $event.preventDefault(); $event.stopPropagation();
                $el.querySelector('input')?.focus();
            } else if (i > 0) {
                $event.preventDefault(); $event.stopPropagation();
                opts[i - 1].focus();
            }
        } else if ($event.key === 'Escape') {
            open = false;
            $el.querySelector('input')?.focus();
        }
    "
>
    @if($label !== '')
        <flux:label>
            {{ $label }}
            @if($required)<span class="text-rose-500">*</span>@endif
        </flux:label>
    @endif

    <div class="relative">
        @if($value && $selectedLabel !== '')
            <div
                tabindex="0"
                data-form-stop
                x-on:keydown.space.prevent.stop="$el.querySelector('button')?.click()"
                x-on:keydown.enter.prevent.stop="window.focusNextFormField?.($el)"
                class="flex items-center justify-between rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-white/10 dark:bg-zinc-800"
            >
                <span class="font-medium text-zinc-900 dark:text-white">{{ $selectedLabel }}</span>
                <flux:button size="xs" variant="ghost" icon="x-mark" type="button" wire:click="clearSelection" />
            </div>
        @else
            @php
                $firstSuggestion = $this->suggestions[0] ?? null;
                $ghostSuffix = '';
                if ($firstSuggestion && $search !== '' && mb_stripos((string) $firstSuggestion['label'], $search) === 0) {
                    $ghostSuffix = mb_substr((string) $firstSuggestion['label'], mb_strlen($search));
                }
            @endphp

            <div class="relative">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    x-on:focus="open = true"
                    x-on:input="open = true"
                    x-on:keydown.enter="if ($refs.acceptGhost) { $event.preventDefault(); $event.stopPropagation(); $refs.acceptGhost.click(); }"
                    icon="magnifying-glass"
                    :placeholder="$placeholder !== '' ? $placeholder : __('Type at least :n letters…', ['n' => $minChars])"
                    autocomplete="off"
                />

                @if($ghostSuffix !== '')
                    <div class="pointer-events-none absolute inset-y-0 left-0 right-0 flex items-center ps-10 pe-3 text-base sm:text-sm leading-[1.375rem]">
                        <span class="invisible whitespace-pre">{{ $search }}</span>
                        <span class="text-zinc-400 dark:text-zinc-500 whitespace-pre truncate">{{ $ghostSuffix }}<span class="ms-2 text-[10px] font-medium uppercase tracking-wider opacity-70">↵</span></span>
                    </div>
                    <button
                        type="button"
                        x-ref="acceptGhost"
                        class="hidden"
                        wire:click="selectOption({{ json_encode($firstSuggestion['id']) }}, @js($firstSuggestion['label']))"
                    ></button>
                @endif
            </div>

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
                                data-typeahead-option
                                wire:click="selectOption({{ json_encode($opt['id']) }}, @js($opt['label']))"
                                x-on:click="open = false"
                                class="block w-full cursor-pointer px-3 py-2 text-left text-sm text-zinc-900 transition-colors hover:bg-indigo-50 hover:text-indigo-700 focus:bg-indigo-50 focus:text-indigo-700 focus:outline-none dark:text-white dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300 dark:focus:bg-indigo-500/10 dark:focus:text-indigo-300"
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
