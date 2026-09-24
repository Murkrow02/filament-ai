<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Agent\Solving\Strategies;
use Murkrow\FilamentAi\Contracts\SolveStrategy;

/**
 * The shape of one iterative run: what counts as a solution, how wide to
 * search, and when to stop.
 *
 * Everything left null falls back to `filament-ai.agent.solving`, so a caller states
 * only what it wants to differ. The four budgets are independent and the first
 * one to run out ends the run -- a search that cannot be stopped is not a
 * feature.
 */
final readonly class SolveOptions
{
    /**
     * @param  string  $criteria  what the verifier checks an answer against
     * @param  array<string, mixed>  $context  extra facts handed to every attempt, and stored on the run
     * @param  class-string|null  $assistant  the agent to use; null takes filament-ai.agent.assistant
     * @param  class-string<SolveStrategy>|null  $strategy  how to go about it; null takes filament-ai.agent.solving.strategy
     */
    public function __construct(
        public string $criteria = '',
        public ?int $attemptsPerWave = null,
        public ?int $maxWaves = null,
        public ?int $maxTokens = null,
        public ?int $maxCostMicros = null,
        public ?int $maxSeconds = null,
        public array $context = [],
        public ?string $assistant = null,
        public ?float $temperatureSpread = null,
        // Last, so a caller passing the others positionally is unaffected.
        public ?string $strategy = null,
    ) {}

    public function attemptsPerWave(): int
    {
        return max(1, $this->attemptsPerWave ?? (int) config('filament-ai.agent.solving.attempts_per_wave', 4));
    }

    public function maxWaves(): int
    {
        $phases = Strategies::for($this->strategy())->phases();

        // A method with three named steps is not improved by a fourth wave of
        // the last one, so the strategy wins over the configured ceiling --
        // but an explicit maxWaves from the caller still wins over both.
        return max(1, $this->maxWaves ?? $phases ?? (int) config('filament-ai.agent.solving.max_waves', 3));
    }

    public function maxTokens(): ?int
    {
        return $this->positive($this->maxTokens ?? config('filament-ai.agent.solving.max_tokens'));
    }

    public function maxCostMicros(): ?int
    {
        return $this->positive($this->maxCostMicros ?? config('filament-ai.agent.solving.max_cost_micros'));
    }

    public function maxSeconds(): ?int
    {
        return $this->positive($this->maxSeconds ?? config('filament-ai.agent.solving.max_seconds'));
    }

    public function assistant(): string
    {
        return $this->assistant ?? (string) config('filament-ai.agent.assistant', PanelAssistant::class);
    }

    /**
     * @return class-string<SolveStrategy>
     */
    public function strategy(): string
    {
        return $this->strategy !== null && is_a($this->strategy, SolveStrategy::class, allow_string: true)
            ? $this->strategy
            : Strategies::configured();
    }

    /**
     * How far apart the attempts of one wave are told to think. Zero makes
     * them near-copies of each other, which wastes the whole point of running
     * several.
     */
    public function temperatureSpread(): float
    {
        return max(0.0, $this->temperatureSpread ?? (float) config('filament-ai.agent.solving.temperature_spread', 0.4));
    }

    /**
     * The most agent calls this run may make, for the estimate shown before
     * it starts. The judge adds one call per attempt on top.
     */
    public function maxAgentCalls(): int
    {
        return Strategies::plannedAttempts(
            Strategies::for($this->strategy()),
            $this->maxWaves(),
            $this->attemptsPerWave(),
        );
    }

    private function positive(mixed $value): ?int
    {
        $value = $value === null || $value === '' ? null : (int) $value;

        return $value === null || $value <= 0 ? null : $value;
    }
}
