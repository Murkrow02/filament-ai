<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

use Countable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Murkrow\FilamentAi\Contracts\SourceFilter;
use Murkrow\FilamentAi\Sources\Filters\FilterValue;

/**
 * A source's filters, keyed by name.
 *
 * One object owns both halves of the contract: what a source exposes (names,
 * labels -- read by the CLI listing and the Filament form) and how a set of
 * caller-supplied values becomes query constraints.
 */
final class FilterSet implements Countable
{
    /** @var array<string, SourceFilter> */
    private array $filters = [];

    public function __construct(SourceFilter ...$filters)
    {
        foreach ($filters as $filter) {
            $this->filters[$filter->name()] = $filter;
        }
    }

    /**
     * @param  iterable<SourceFilter>  $filters
     */
    public static function make(iterable $filters): self
    {
        return new self(...$filters);
    }

    /**
     * @return array<string, SourceFilter>
     */
    public function all(): array
    {
        return $this->filters;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->filters);
    }

    public function get(string $name): ?SourceFilter
    {
        return $this->filters[$name] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->filters === [];
    }

    public function count(): int
    {
        return count($this->filters);
    }

    /**
     * Apply every filter that has a value, falling back to its default.
     *
     * A blank value means "not filtered", which is why a boolean default of
     * false still constrains: false is a value, '' and null are not.
     *
     * @param  Builder<covariant Model>  $query
     * @param  array<string, mixed>  $values
     */
    public function applyTo(Builder $query, array $values): void
    {
        foreach ($this->filters as $name => $filter) {
            $value = $values[$name] ?? $filter->default();

            if (FilterValue::isBlank($value)) {
                continue;
            }

            $filter->apply($query, $value);
        }
    }
}
