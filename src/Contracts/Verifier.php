<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Murkrow\FilamentAi\Data\Verdict;
use Murkrow\FilamentAi\Models\SolveAttempt;

/**
 * Decides whether an attempt is good enough to stop.
 *
 * The package ships one implementation, a language model judging against the
 * criteria the caller wrote. The contract exists so an application that
 * already knows what "correct" means -- a treasure hunt holding the answer, a
 * checksum, a test suite -- can bind something deterministic instead and pay
 * nothing per attempt.
 *
 * An implementation must not throw: a verifier that cannot decide returns
 * `Verdict::unjudged()`, so one broken judgement does not fail the run.
 */
interface Verifier
{
    public function verify(SolveAttempt $attempt, string $goal, string $criteria): Verdict;
}
