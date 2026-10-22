<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Metrics;

use Closure;

/**
 * Something a metric can be broken down by: an operator, a store, an item.
 *
 * `path` is a column of the metric's model ("status"), a chain of belongs-to
 * relationships ending on a column ("user.name", "item.itemData.name") that
 * is joined automatically, or -- with `join` -- any column or expression the
 * host's join callback makes reachable ("suppliers.business_name").
 */
final readonly class Dimension
{
    /**
     * @param  (Closure(\Illuminate\Database\Query\Builder): mixed)|null  $join
     * @param  (Closure(): bool)|null  $when  offered only when it returns true
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $path,
        public ?Closure $join = null,
        public ?Closure $when = null,
    ) {}

    public function available(): bool
    {
        return $this->when === null || (bool) ($this->when)();
    }
}
