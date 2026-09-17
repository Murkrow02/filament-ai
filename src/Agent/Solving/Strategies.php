<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Solving;

use Murkrow\FilamentAi\Contracts\SolveStrategy;

/**
 * Turns the class name stored on a run back into a strategy.
 *
 * A run keeps the class it was started with rather than reading the config
 * again: the config may have changed by the time the second wave runs, and a
 * run that switches method halfway is unreadable afterwards.
 */
final class Strategies
{
    public static function for(?string $class): SolveStrategy
    {
        $class ??= self::configured();

        if (! is_a($class, SolveStrategy::class, allow_string: true)) {
            // A strategy that was renamed or removed must not take the run
            // down with it: the default one can always run the goal.
            $class = DefaultStrategy::class;
        }

        return app($class);
    }

    /**
     * How many attempts a run can make in total, phase by phase: a strategy
     * whose steps run two, two and one attempts plans five, not waves times
     * the default.
     */
    public static function plannedAttempts(SolveStrategy $strategy, int $waves, int $defaultAttempts): int
    {
        $total = 0;

        for ($wave = 1; $wave <= $waves; $wave++) {
            $total += $strategy->phaseFor($wave)->attempts(max(1, $defaultAttempts));
        }

        return $total;
    }

    /**
     * @return class-string<SolveStrategy>
     */
    public static function configured(): string
    {
        $class = (string) config('rag.agent.solving.strategy', DefaultStrategy::class);

        return is_a($class, SolveStrategy::class, allow_string: true) ? $class : DefaultStrategy::class;
    }
}
