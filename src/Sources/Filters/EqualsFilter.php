<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Illuminate\Database\Eloquent\Builder;

final class EqualsFilter extends ColumnFilter
{
    public function apply(Builder $query, mixed $value): void
    {
        $query->where($this->column, $value);
    }
}
