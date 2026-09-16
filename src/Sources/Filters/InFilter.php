<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Restrict to a fixed set of choices, rendered as a multi-select.
 */
final class InFilter extends ColumnFilter
{
    /**
     * @param  array<string|int, string>  $options
     */
    public function __construct(
        string $name,
        string $column,
        private readonly array $options = [],
        ?string $label = null,
        mixed $default = null,
    ) {
        parent::__construct($name, $column, $label, $default);
    }

    /**
     * @return array<string|int, string>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function apply(Builder $query, mixed $value): void
    {
        $query->whereIn($this->column, FilterValue::list($value));
    }
}
