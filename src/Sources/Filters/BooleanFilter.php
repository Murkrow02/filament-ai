<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Constrain a boolean column.
 *
 * Note the interaction with the default: FilterSet skips blank values, and
 * `false` is not blank, so a filter declared with `default: false` constrains
 * the query on every run unless the caller passes true. That is what makes
 * "exclude the bad rows unless asked" a one-liner.
 */
final class BooleanFilter extends ColumnFilter
{
    public function apply(Builder $query, mixed $value): void
    {
        $query->where($this->column, FilterValue::bool($value));
    }
}
