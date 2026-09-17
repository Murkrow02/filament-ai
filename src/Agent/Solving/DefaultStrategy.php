<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Solving;

use Murkrow\FilamentAi\Contracts\SolveStrategy;
use Murkrow\FilamentAi\Contracts\Verifier;
use Murkrow\FilamentAi\Data\SolvePhase;
use Murkrow\FilamentAi\Models\SolveRun;

/**
 * The method a run has when nobody described one: try the goal with every
 * tool available, as many times as the budget allows, learning only from what
 * the judge rejected.
 *
 * It is deliberately unopinionated. Anything that knows *how* its problem is
 * usually solved beats it, which is the reason `SolveStrategy` exists.
 */
class DefaultStrategy implements SolveStrategy
{
    public function label(): string
    {
        return (string) __('rag::rag.solving.default_strategy');
    }

    public function phases(): ?int
    {
        // No fixed number of steps: the waves are however many the caller
        // budgeted for.
        return null;
    }

    public function phaseFor(int $wave): SolvePhase
    {
        return new SolvePhase;
    }

    public function promptFor(SolveRun $run, SolvePhase $phase, int $position, string $feedback): string
    {
        $lines = ['GOAL:', $run->goal];

        if (trim($phase->instructions) !== '') {
            $lines[] = '';
            $lines[] = 'HOW TO GO ABOUT IT:';
            $lines[] = trim($phase->instructions);
        }

        if (! empty($run->context)) {
            $lines[] = '';
            $lines[] = 'CONTEXT:';
            $lines[] = (string) json_encode($run->context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        if (filled($run->criteria)) {
            $lines[] = '';
            $lines[] = 'WHAT COUNTS AS A SOLUTION:';
            $lines[] = (string) $run->criteria;
        }

        if (trim($feedback) !== '') {
            $lines[] = '';
            $lines[] = 'EARLIER ATTEMPTS WERE REJECTED:';
            $lines[] = trim($feedback);
            $lines[] = 'Do not repeat them. Try a different line of reasoning.';
        }

        $lines[] = '';
        // The marker is what lets the run show a one-line answer next to a
        // page of reasoning.
        $lines[] = 'Work it out with the tools you have rather than guessing, then finish with a single line: ANSWER: <your answer>';

        return implode("\n", $lines);
    }

    public function criteriaFor(SolveRun $run): string
    {
        return (string) $run->criteria;
    }

    public function verifier(): ?Verifier
    {
        return null;
    }
}
