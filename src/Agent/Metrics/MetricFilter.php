<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Metrics;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A narrowing argument of a metric ("store", "operator", "supplier"), applied
 * by the host's own callback.
 */
final readonly class MetricFilter
{
    /**
     * @param  Closure(Builder, mixed): mixed  $apply
     * @param  array<string, string>|(Closure(): array<string, string>)|null  $options  value => label
     * @param  (Closure(): bool)|null  $when  offered only when it returns true
     */
    public function __construct(
        public string $name,
        public string $description,
        public Closure $apply,
        public array|Closure|null $options = null,
        public ?Closure $when = null,
    ) {}

    public function available(): bool
    {
        return $this->when === null || (bool) ($this->when)();
    }

    /**
     * @return array<string, string>|null
     */
    public function options(): ?array
    {
        $options = $this->options instanceof Closure ? ($this->options)() : $this->options;

        return $options === null ? null : array_map(strval(...), $options);
    }
}
