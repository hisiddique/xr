<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class Search
{
    private const ESCAPE = '!';

    public const EXACT = 'exact';

    public const STARTS = 'starts';

    public const ENDS = 'ends';

    public const CONTAINS = 'contains';

    public static function escape(string $term): string
    {
        return preg_replace('/([%_'.preg_quote(self::ESCAPE, '/').'])/', self::ESCAPE.'$1', $term);
    }

    public static function pattern(string $term, string $mode): string
    {
        $escaped = self::escape($term);

        return match ($mode) {
            self::EXACT => $escaped,
            self::STARTS => $escaped.'%',
            self::ENDS => '%'.$escaped,
            default => '%'.$escaped.'%',
        };
    }

    public static function contains(string $term): string
    {
        return self::pattern($term, self::CONTAINS);
    }

    public static function startsWith(string $term): string
    {
        return self::pattern($term, self::STARTS);
    }

    /**
     * Match rows where any column matches the term in the given mode (default: contains). A dotted column
     * ("customer.company_name") matches through that relation.
     *
     * @param  array<int, string>  $columns
     */
    public static function filter(Builder $query, ?string $term, array $columns, string $mode = self::CONTAINS): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = self::pattern($term, $mode);
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
     * Order rows by how closely a column matches the term: exact, then starts with, then ends with, then the
     * rest. Add it before any other ordering, and skip it when the user has picked an explicit sort. The
     * patterns are inlined (not bound) because bound orderByRaw values have misaligned with other subquery
     * bindings on some queries here.
     *
     * @param  array<int, string>  $columns
     */
    public static function rankStartsWithFirst(Builder $query, ?string $term, array $columns): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $branches = '';

        foreach ([self::EXACT, self::STARTS, self::ENDS] as $rank => $mode) {
            $branches .= ' when '.self::anyColumnLike($query, $term, $columns, $mode)." then {$rank}";
        }

        return $query->orderByRaw("case{$branches} else 3 end");
    }

    /**
     * @param  array<int, string>  $columns
     */
    private static function anyColumnLike(Builder $query, string $term, array $columns, string $mode): string
    {
        $pattern = $query->getConnection()->getPdo()->quote(self::pattern($term, $mode));
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

        return '('.implode(' or ', $conditions).')';
    }
}
