<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Illuminate\Support\Str;
use Murkrow\FilamentAi\Contracts\SourceFilter;

/**
 * Base for every filter that narrows on a single column.
 *
 * Name and column are separate because a source routinely exposes two filters
 * over the same column -- "ids" and "id_range" both constrain `id` -- and the
 * name is what the CLI option and the form state path address.
 */
abstract class ColumnFilter implements SourceFilter
{
    public function __construct(
        protected readonly string $name,
        protected readonly string $column,
        protected readonly ?string $label = null,
        protected readonly mixed $default = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function column(): string
    {
        return $this->column;
    }

    public function label(): string
    {
        return $this->label ?? Str::headline($this->name);
    }

    public function default(): mixed
    {
        return $this->default;
    }
}
