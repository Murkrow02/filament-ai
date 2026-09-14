<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Substring match. Intended for narrowing an ingestion run by hand, not for
 * search: retrieval is the vector store's job.
 */
final class LikeFilter extends ColumnFilter
{
    public function apply(Builder $query, mixed $value): void
    {
        $query->where($this->column, 'like', '%'.$value.'%');
    }
}
