<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Inclusive lower and/or upper bound. Either side may be omitted.
 */
final class RangeFilter extends ColumnFilter
{
    public function apply(Builder $query, mixed $value): void
    {
        [$from, $to] = FilterValue::bounds($value);

        if ($from !== null) {
            $query->where($this->column, '>=', $from);
        }

        if ($to !== null) {
            $query->where($this->column, '<=', $to);
        }
    }
}
