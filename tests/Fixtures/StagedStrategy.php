<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Murkrow\FilamentAi\Contracts\SolveStrategy;
use Murkrow\FilamentAi\Contracts\Verifier;
use Murkrow\FilamentAi\Data\SolvePhase;
use Murkrow\FilamentAi\Models\SolveRun;

/**
 * A two-step method, standing in for a host's own: mechanical tricks first,
 * the archive second, each with only the tools its step is about.
 *
 * This is the shape a treasure hunt writes -- try the anagrams, then look the
 * name up -- and the reason the package does not hardcode a single prompt.
 */
class StagedStrategy implements SolveStrategy
{
    public function label(): string
    {
        return 'Staged';
    }

    public function phases(): ?int
    {
        return 2;
    }

    public function phaseFor(int $wave): SolvePhase
    {
        return $wave <= 1
            ? new SolvePhase(
                label: 'anagrams',
                instructions: 'Run the letters through every permutation before you think about meaning.',
                attempts: 2,
                temperature: 0.9,
                tools: ['run_code'],
                maxSteps: 7,
            )
            : new SolvePhase(
                label: 'archive',
                instructions: 'Search the indexed documents for the words the first step produced.',
                attempts: 1,
                tools: ['search_knowledge'],
            );
    }

    public function promptFor(SolveRun $run, SolvePhase $phase, int $position, string $feedback): string
    {
        return implode("\n", array_filter([
            'STEP: '.$phase->label,
            $phase->instructions,
            'GOAL: '.$run->goal,
            $feedback === '' ? null : 'REJECTED BEFORE: '.$feedback,
            'Finish with a single line: ANSWER: <your answer>',
        ]));
    }

    public function criteriaFor(SolveRun $run): string
    {
        return trim($run->criteria.' It must be a single word.');
    }

    public function verifier(): ?Verifier
    {
        return null;
    }
}
