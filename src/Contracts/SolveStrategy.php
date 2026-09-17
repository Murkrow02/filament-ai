<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Murkrow\FilamentAi\Data\SolvePhase;
use Murkrow\FilamentAi\Models\SolveRun;

/**
 * How a run goes about solving something.
 *
 * The engine -- waves, budgets, judging, bookkeeping -- is the package's and
 * is the same for everybody. The *method* is not: a treasure hunt wants the
 * anagrams tried before the archive is searched and the archive before the
 * map, with different tools at each step, and no generic prompt can express
 * that. So the method lives in the application, as one of these.
 *
 * The package ships `DefaultStrategy`, which is the behaviour every run had
 * before this contract existed: one phase, repeated, with every tool.
 *
 * Implementations are resolved from the container and must be stateless: a
 * run is a sequence of queued jobs and each of them builds the strategy
 * again.
 */
interface SolveStrategy
{
    /**
     * A short name for the panel. Not an identifier: the class is.
     */
    public function label(): string;

    /**
     * How many waves this method takes, or null to let the caller's `maxWaves`
     * decide. A strategy that names its phases sets the number of waves,
     * because a method with three steps is not improved by a fourth.
     */
    public function phases(): ?int;

    /**
     * The phase for a given wave, counted from 1. A strategy with fewer
     * phases than waves repeats its last one.
     */
    public function phaseFor(int $wave): SolvePhase;

    /**
     * The prompt for one attempt: the goal, the phase's instructions, and what
     * the previous wave got wrong.
     */
    public function promptFor(SolveRun $run, SolvePhase $phase, int $position, string $feedback): string;

    /**
     * What an answer is judged against. Usually the run's own criteria; a
     * strategy may sharpen them with what it knows about the domain.
     */
    public function criteriaFor(SolveRun $run): string;

    /**
     * The verifier to judge this run's attempts with, or null for the one
     * bound in the container (the language-model judge). A strategy that can
     * check an answer itself returns something deterministic here and pays
     * nothing per attempt.
     */
    public function verifier(): ?Verifier;
}
