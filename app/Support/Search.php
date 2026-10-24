<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class Search
{
    private const ESCAPE = '!';

    public static function escape(string $term): string
    {
        return preg_replace('/([%_'.preg_quote(self::ESCAPE, '/').'])/', self::ESCAPE.'$1', $term);
    }

    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    public static function startsWith(string $term): string
    {
        return self::escape($term).'%';
    }

    /**
     * Match rows where any column contains the term. A dotted column ("customer.company_name")
     * matches through that relation.
     *
     * @param  array<int, string>  $columns
     */
    public static function filter(Builder $query, ?string $term, array $columns): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = self::contains($term);
        $grammar = $query->getQuery()->getGrammar();

        return $query->where(function (Builder $q) use ($columns, $like, $grammar): void {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    [$relation, $related] = explode('.', $column, 2);
                    $q->orWhereHas($relation, fn (Builder $r) => $r->whereRaw(
                        $grammar->wrap($r->getModel()->qualifyColumn($related)).' like ? escape \''.self::ESCAPE.'\'',
                        [$like],
                    ));
                } else {
                    $q->orWhereRaw(
                        $grammar->wrap($q->getModel()->qualifyColumn($column)).' like ? escape \''.self::ESCAPE.'\'',
                        [$like],
                    );
                }
            }
        });
    }

    /**
     * Order rows whose columns start with the term ahead of the rest. Add it before any other ordering,
     * and skip it when the user has picked an explicit sort. The pattern is inlined (not bound) because
     * bound orderByRaw values have misaligned with other subquery bindings on some queries here.
     *
     * @param  array<int, string>  $columns
     */
    public static function rankStartsWithFirst(Builder $query, ?string $term, array $columns): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $pattern = $query->getConnection()->getPdo()->quote(self::startsWith($term));
        $grammar = $query->getQuery()->getGrammar();
        $escape = ' escape \''.self::ESCAPE.'\'';
        $conditions = [];

        foreach ($columns as $column) {
            if (str_contains($column, '.')) {
                [$relation, $related] = explode('.', $column, 2);
                $relationQuery = $query->getModel()->{$relation}();
                $exists = $relationQuery
                    ->getRelationExistenceQuery($relationQuery->getRelated()->newQuery(), $query)
                    ->whereRaw($grammar->wrap($relationQuery->getRelated()->qualifyColumn($related))." like {$pattern}{$escape}");
                $conditions[] = 'exists ('.$exists->toSql().')';
            } else {
                $conditions[] = $grammar->wrap($query->getModel()->qualifyColumn($column))." like {$pattern}{$escape}";
            }
        }

        return $query->orderByRaw('case when '.implode(' or ', $conditions).' then 0 else 1 end');
    }
}
