<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Metrics;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What a metric measures, over which date, and how it can be broken down and
 * narrowed. Filled by `Metric::define()`.
 */
final class MetricDefinition
{
    private ?string $label = null;

    private ?string $description = null;

    private ?string $date = null;

    /** @var array<string, Measure> */
    private array $measures = [];

    /** @var array<string, Dimension> */
    private array $dimensions = [];

    /** @var array<string, MetricFilter> */
    private array $filters = [];

    /** @var array<string, array{0: string, 1: string}>|(Closure(): array<string, array{0: string, 1: string}>)|null */
    private array|Closure|null $periods = null;

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * What the numbers are and what they leave out ("completed sales only"):
     * goes into the tool description.
     */
    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * The date or datetime column a period narrows on.
     */
    public function date(string $column): self
    {
        $this->date = $column;

        return $this;
    }

    /**
     * @param  (Closure(): bool)|null  $when
     */
    public function count(string $name, ?string $label = null, ?string $distinct = null, ?string $unit = null, ?Closure $when = null): self
    {
        return $this->measure(new Measure($name, $label ?? Str::headline($name), Measure::COUNT, $distinct, $distinct !== null, $unit, when: $when));
    }

    /**
     * @param  (Closure(): bool)|null  $when
     */
    public function sum(string $name, string $column, ?string $label = null, ?string $unit = null, int|float $divisor = 1, ?Closure $when = null): self
    {
        return $this->measure(new Measure($name, $label ?? Str::headline($name), Measure::SUM, $column, unit: $unit, divisor: $divisor, when: $when));
    }

    /**
     * @param  (Closure(): bool)|null  $when
     */
    public function avg(string $name, string $column, ?string $label = null, ?string $unit = null, int|float $divisor = 1, ?Closure $when = null): self
    {
        return $this->measure(new Measure($name, $label ?? Str::headline($name), Measure::AVG, $column, unit: $unit, divisor: $divisor, when: $when));
    }

    /**
     * @param  (Closure(): bool)|null  $when
     */
    public function min(string $name, string $column, ?string $label = null, ?string $unit = null, int|float $divisor = 1, ?Closure $when = null): self
    {
        return $this->measure(new Measure($name, $label ?? Str::headline($name), Measure::MIN, $column, unit: $unit, divisor: $divisor, when: $when));
    }

    /**
     * @param  (Closure(): bool)|null  $when
     */
    public function max(string $name, string $column, ?string $label = null, ?string $unit = null, int|float $divisor = 1, ?Closure $when = null): self
    {
        return $this->measure(new Measure($name, $label ?? Str::headline($name), Measure::MAX, $column, unit: $unit, divisor: $divisor, when: $when));
    }

    public function measure(Measure $measure): self
    {
        $this->assertName($measure->name);
        $this->measures[$measure->name] = $measure;

        return $this;
    }

    /**
     * @param  (Closure(\Illuminate\Database\Query\Builder): mixed)|null  $join
     * @param  (Closure(): bool)|null  $when
     */
    public function dimension(string $name, string $path, ?string $label = null, ?Closure $join = null, ?Closure $when = null): self
    {
        $this->assertName($name);
        $this->dimensions[$name] = new Dimension($name, $label ?? Str::headline($name), $path, $join, $when);

        return $this;
    }

    /**
     * @param  Closure(\Illuminate\Database\Eloquent\Builder, mixed): mixed  $apply
     * @param  array<string, string>|(Closure(): array<string, string>)|null  $options
     * @param  (Closure(): bool)|null  $when
     */
    public function filter(string $name, string $description, Closure $apply, array|Closure|null $options = null, ?Closure $when = null): self
    {
        $this->assertName($name);

        if (in_array($name, Metric::RESERVED, true)) {
            throw new InvalidArgumentException("[{$name}] is a metric argument already; name the filter differently.");
        }

        $this->filters[$name] = new MetricFilter($name, $description, $apply, $options, $when);

        return $this;
    }

    /**
     * Periods the application names itself -- seasons, fiscal years, sales
     * campaigns -- as name => [from, to] (Y-m-d). A closure is read on every
     * call, so it can build them from configuration or from today's date.
     *
     * @param  array<string, array{0: string, 1: string}>|(Closure(): array<string, array{0: string, 1: string}>)  $periods
     */
    public function periods(array|Closure $periods): self
    {
        $this->periods = $periods;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    /**
     * @return array<string, Measure>
     */
    public function measures(): array
    {
        return array_filter($this->measures, static fn (Measure $measure): bool => $measure->available());
    }

    /**
     * @return array<string, Dimension>
     */
    public function dimensions(): array
    {
        return array_filter($this->dimensions, static fn (Dimension $dimension): bool => $dimension->available());
    }

    /**
     * @return array<string, MetricFilter>
     */
    public function filters(): array
    {
        return array_filter($this->filters, static fn (MetricFilter $filter): bool => $filter->available());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function namedPeriods(): array
    {
        $periods = $this->periods instanceof Closure ? ($this->periods)() : ($this->periods ?? []);

        return array_map(static fn (array $range): array => [(string) $range[0], (string) $range[1]], $periods);
    }

    private function assertName(string $name): void
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Metric names must be letters, digits and underscores; [{$name}] is not.");
        }
    }
}
