<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Contracts\SourceFilter;

/**
 * Escape hatch for a constraint no descriptor can express: a join, a subquery,
 * a scope taking several arguments.
 */
final class CallbackFilter implements SourceFilter
{
    /**
     * @param  Closure(Builder<covariant Model>, mixed): void  $callback
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $callback,
        private readonly ?string $label = null,
        private readonly mixed $default = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label ?? Str::headline($this->name);
    }

    public function default(): mixed
    {
        return $this->default;
    }

    public function apply(Builder $query, mixed $value): void
    {
        ($this->callback)($query, $value);
    }
}
