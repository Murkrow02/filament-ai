<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Restrict to an explicit list of identifiers.
 *
 * An empty list is never reached -- FilterSet skips blank values -- but a list
 * that parses to nothing still compiles to `whereIn(col, [])`, i.e. "match
 * nothing", which is deliberate: an allow-list that resolves to nothing must
 * expose nothing.
 */
final class IdsFilter extends ColumnFilter
{
    public function apply(Builder $query, mixed $value): void
    {
        $query->whereIn($this->column, FilterValue::list($value));
    }
}
