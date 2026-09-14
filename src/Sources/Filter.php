<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Murkrow\FilamentAi\Sources\Filters\BooleanFilter;
use Murkrow\FilamentAi\Sources\Filters\CallbackFilter;
use Murkrow\FilamentAi\Sources\Filters\DateRangeFilter;
use Murkrow\FilamentAi\Sources\Filters\EqualsFilter;
use Murkrow\FilamentAi\Sources\Filters\IdsFilter;
use Murkrow\FilamentAi\Sources\Filters\InFilter;
use Murkrow\FilamentAi\Sources\Filters\LikeFilter;
use Murkrow\FilamentAi\Sources\Filters\NullFilter;
use Murkrow\FilamentAi\Sources\Filters\RangeFilter;

/**
 * Named constructors for the shipped filters.
 *
 * A source declares its filters as `Filter::ids('ids', 'id')`; the column
 * defaults to the name, so single-purpose filters read as one argument.
 */
final class Filter
{
    public static function ids(string $name, ?string $column = null, ?string $label = null): IdsFilter
    {
        return new IdsFilter($name, $column ?? $name, $label);
    }

    /**
     * @param  array<string|int, string>  $options
     */
    public static function in(string $name, array $options, ?string $column = null, ?string $label = null, mixed $default = null): InFilter
    {
        return new InFilter($name, $column ?? $name, $options, $label, $default);
    }

    public static function range(string $name, ?string $column = null, ?string $label = null): RangeFilter
    {
        return new RangeFilter($name, $column ?? $name, $label);
    }

    public static function dateRange(string $name, ?string $column = null, ?string $label = null): DateRangeFilter
    {
        return new DateRangeFilter($name, $column ?? $name, $label);
    }

    public static function like(string $name, ?string $column = null, ?string $label = null): LikeFilter
    {
        return new LikeFilter($name, $column ?? $name, $label);
    }

    public static function equals(string $name, ?string $column = null, ?string $label = null, mixed $default = null): EqualsFilter
    {
        return new EqualsFilter($name, $column ?? $name, $label, $default);
    }

    public static function boolean(string $name, ?string $column = null, ?string $label = null, ?bool $default = null): BooleanFilter
    {
        return new BooleanFilter($name, $column ?? $name, $label, $default);
    }

    public static function isNull(string $name, ?string $column = null, ?string $label = null, ?bool $default = null): NullFilter
    {
        return new NullFilter($name, $column ?? $name, $label, $default);
    }

    /**
     * @param  Closure(Builder<covariant Model>, mixed): void  $callback
     */
    public static function callback(string $name, Closure $callback, ?string $label = null, mixed $default = null): CallbackFilter
    {
        return new CallbackFilter($name, $callback, $label, $default);
    }
}
