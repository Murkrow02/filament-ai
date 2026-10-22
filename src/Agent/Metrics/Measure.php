<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Metrics;

use Closure;

/**
 * One number a metric computes: a count, a sum, an average, a minimum or a
 * maximum over a column or a SQL expression of the host's own.
 */
final readonly class Measure
{
    public const COUNT = 'count';

    public const SUM = 'sum';

    public const AVG = 'avg';

    public const MIN = 'min';

    public const MAX = 'max';

    /**
     * @param  string|null  $column  a column ("total", "sales.total") or a SQL expression; null counts rows
     * @param  bool  $distinct  count distinct values of $column
     * @param  string|null  $unit  e.g. "EUR", "pieces"; shown with the values
     * @param  int|float  $divisor  stored value / divisor = reported value (cents: 100)
     * @param  (Closure(): bool)|null  $when  offered only when it returns true
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $function,
        public ?string $column = null,
        public bool $distinct = false,
        public ?string $unit = null,
        public int|float $divisor = 1,
        public int $decimals = 2,
        public ?Closure $when = null,
    ) {}

    public function available(): bool
    {
        return $this->when === null || (bool) ($this->when)();
    }

    public function value(mixed $raw): int|float
    {
        if ($raw === null) {
            return 0;
        }

        if ($this->function === self::COUNT) {
            return (int) $raw;
        }

        $value = (float) $raw / $this->divisor;

        return round($value, $this->decimals);
    }
}
