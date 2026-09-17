<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * One step of a solving strategy: what this wave is supposed to try, and with
 * what.
 *
 * A generic run has a single phase repeated until the budget runs out. A
 * domain that knows its own method -- try the anagrams first, then the
 * archive, then the map -- describes that method as a sequence of these, one
 * per wave, and the attempts of each wave get only the instructions and the
 * tools that step needs.
 *
 * Everything is nullable on purpose: null means "whatever the run was
 * configured with", so a phase states only what it wants to differ.
 */
final readonly class SolvePhase
{
    /**
     * @param  string  $label  shown next to the wave in the panel
     * @param  string  $instructions  prepended to the attempt's prompt
     * @param  int|null  $attempts  how many attempts this wave runs; null takes the run's own number
     * @param  float|null  $temperature  pins every attempt of the wave; null spreads them as usual
     * @param  float|null  $temperatureSpread  how far apart the attempts are spread; null takes the run's
     * @param  list<string>|null  $tools  tool names this phase may use; null means every tool the agent has
     * @param  int|null  $maxSteps  tool round trips one attempt may take; null takes the agent's own
     */
    public function __construct(
        public string $label = '',
        public string $instructions = '',
        public ?int $attempts = null,
        public ?float $temperature = null,
        public ?float $temperatureSpread = null,
        public ?array $tools = null,
        public ?int $maxSteps = null,
    ) {}

    public function attempts(int $default): int
    {
        return max(1, $this->attempts ?? $default);
    }
}
