<?php

namespace App\Traits;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait HasDeletionBlockers
{
    /**
     * @return array<string, string> singular label => relation method
     */
    abstract protected function deletionBlockerRelations(): array;

    /**
     * @return array<string, int> singular label => related record count, only where records exist
     */
    public function deletionBlockers(): array
    {
        return collect($this->deletionBlockerRelations())
            ->map(fn (string $relation) => $this->{$relation}()->count())
            ->filter()
            ->all();
    }

    public function noRelatedRecordsMessage(string $name): string
    {
        $labels = collect(array_keys($this->deletionBlockerRelations()))
            ->map(fn (string $label) => Str::plural($label))
            ->all();

        return __(':name has no :related, so it can be deleted safely.', [
            'name' => $name,
            'related' => Arr::join($labels, ', ', ' or '),
        ]);
    }

    public function deletionBlockedMessage(string $name): ?string
    {
        $blockers = $this->deletionBlockers();

        if ($blockers === []) {
            return null;
        }

        $parts = collect($blockers)
            ->map(fn (int $count, string $label) => $count.' '.Str::plural($label, $count))
            ->values()
            ->all();

        return __(':name still has :related. Delete these first.', [
            'name' => $name,
            'related' => Arr::join($parts, ', ', ' and '),
        ]);
    }
}
