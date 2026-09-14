<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Truthy value keeps the rows where the column IS NULL, falsy the rows where
 * it is not.
 */
final class NullFilter extends ColumnFilter
{
    public function apply(Builder $query, mixed $value): void
    {
        FilterValue::bool($value)
            ? $query->whereNull($this->column)
            : $query->whereNotNull($this->column);
    }
}
