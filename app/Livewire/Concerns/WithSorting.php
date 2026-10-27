<?php

namespace App\Livewire\Concerns;

use App\Support\TableSorts;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

trait WithSorting
{
    #[Url(as: 'sort', except: '')]
    public string $sortColumn = '';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDirection = 'asc';

    /**
     * Columns the component allows sorting on.
     *
     * @return array<int, string>
     */
    protected function sortableColumns(): array
    {
        return property_exists($this, 'sortable') ? (array) $this->sortable : [];
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sortColumn !== $column) {
            $this->sortColumn = $column;
            $this->sortDirection = 'desc';
        } elseif ($this->sortDirection === 'desc') {
            $this->sortDirection = 'asc';
        } else {
            $this->sortColumn = '';
            $this->sortDirection = 'asc';
        }

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /**
     * Key in TableSorts whose configured default applies while the user has not picked a sort.
     */
    protected function defaultSortTable(): ?string
    {
        return null;
    }

    /**
     * @return array{0: string, 1: string} column and direction, or an empty column when nothing applies
     */
    protected function effectiveSort(): array
    {
        if ($this->sortColumn !== '') {
            return in_array($this->sortColumn, $this->sortableColumns(), true)
                ? [$this->sortColumn, $this->sortDirection === 'desc' ? 'desc' : 'asc']
                : ['', 'asc'];
        }

        $table = $this->defaultSortTable();
        [$column, $direction] = $table === null ? ['', 'asc'] : TableSorts::for($table);

        return in_array($column, $this->sortableColumns(), true) ? [$column, $direction] : ['', 'asc'];
    }

    public function applySort(Builder $query): Builder
    {
        [$column, $direction] = $this->effectiveSort();

        if ($column === '') {
            return $query;
        }

        $custom = $this->applyCustomSort($query, $column, $direction);
        $query = $custom instanceof Builder ? $custom : $query->orderBy($column, $direction);

        $secondary = $this->secondarySortColumn();
        if ($secondary !== null && $secondary !== $column) {
            $query->orderBy($secondary, $direction);
        }

        return $query;
    }

    protected function applyCustomSort(Builder $query, string $column, string $direction): ?Builder
    {
        return null;
    }

    /**
     * Tiebreaker column (e.g. doc_number, reference) applied after the
     * user's chosen sort column so rows sharing a date/amount stay stable.
     */
    protected function secondarySortColumn(): ?string
    {
        return null;
    }

    public function sortStateFor(string $column): ?string
    {
        [$active, $direction] = $this->effectiveSort();

        return $active === $column ? $direction : null;
    }
}
