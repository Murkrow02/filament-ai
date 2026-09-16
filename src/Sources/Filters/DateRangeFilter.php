<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * A RangeFilter that compares dates, ignoring the time part of a timestamp.
 */
final class DateRangeFilter extends ColumnFilter
{
    public function apply(Builder $query, mixed $value): void
    {
        [$from, $to] = FilterValue::bounds($value);

        if ($from !== null) {
            $query->whereDate($this->column, '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate($this->column, '<=', $to);
        }
    }
}
