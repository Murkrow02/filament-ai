<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Murkrow\FilamentAi\Contracts\Verifier;
use Murkrow\FilamentAi\Data\Verdict;
use Murkrow\FilamentAi\Models\SolveAttempt;

/**
 * The deterministic verifier the contract exists for: an application holding
 * the answer pays nothing per attempt and never asks a model what it already
 * knows.
 */
class KnownAnswerVerifier implements Verifier
{
    public function __construct(private readonly string $answer) {}

    public function verify(SolveAttempt $attempt, string $goal, string $criteria): Verdict
    {
        $given = mb_strtolower(trim($attempt->finalAnswer()));
        $wanted = mb_strtolower(trim($this->answer));

        return $given === $wanted
            ? Verdict::accept(100, 'Matches the known answer.')
            : Verdict::reject(0, 'Not the known answer.');
    }
}
