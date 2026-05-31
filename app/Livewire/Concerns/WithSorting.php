<?php

namespace App\Livewire\Concerns;

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
            $this->sortDirection = 'asc';
        } elseif ($this->sortDirection === 'asc') {
            $this->sortDirection = 'desc';
        } else {
            $this->sortColumn = '';
            $this->sortDirection = 'asc';
        }

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function applySort(Builder $query): Builder
    {
        if ($this->sortColumn === '' || ! in_array($this->sortColumn, $this->sortableColumns(), true)) {
            return $query;
        }

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';
        $custom = $this->applyCustomSort($query, $this->sortColumn, $direction);
        if ($custom instanceof Builder) {
            return $custom;
        }

        return $query->orderBy($this->sortColumn, $direction);
    }

    protected function applyCustomSort(Builder $query, string $column, string $direction): ?Builder
    {
        return null;
    }

    public function sortStateFor(string $column): ?string
    {
        if ($this->sortColumn !== $column) {
            return null;
        }

        return $this->sortDirection === 'desc' ? 'desc' : 'asc';
    }
}
