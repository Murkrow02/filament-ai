<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Solving;

use Illuminate\Support\Str;
use Murkrow\FilamentAi\Contracts\Verifier;
use Murkrow\FilamentAi\Data\Verdict;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\SolveAttempt;
use Throwable;

/**
 * The shipped verifier: a language model grading against the caller's
 * criteria, through `Judge`'s structured output.
 *
 * It never throws. A judge that errors, times out or answers nonsense returns
 * an unjudged verdict, so one bad judgement costs one attempt rather than the
 * whole run -- and the attempt keeps its answer, because it may still be the
 * best thing the run produced.
 */
final class JudgeVerifier implements Verifier
{
    private const MAX_ANSWER_CHARACTERS = 6000;

    public function verify(SolveAttempt $attempt, string $goal, string $criteria): Verdict
    {
        $answer = trim((string) $attempt->answer);

        if ($answer === '') {
            // No model call needed to know that nothing is not an answer.
            return Verdict::reject(0, 'The attempt produced no answer.');
        }

        try {
            $response = (new Judge($goal, $criteria))->prompt($this->prompt($answer));
        } catch (Throwable $exception) {
            report($exception);

            return Verdict::unjudged('The judge could not be reached.');
        }

        $structured = $response->structured ?? [];

        if (! is_array($structured) || ! array_key_exists('accepted', $structured)) {
            return Verdict::unjudged('The judge answered in an unexpected shape.');
        }

        $verdict = ((bool) $structured['accepted'])
            ? Verdict::accept((int) ($structured['score'] ?? 100), (string) ($structured['reason'] ?? ''))
            : Verdict::reject((int) ($structured['score'] ?? 0), (string) ($structured['reason'] ?? ''));

        $promptTokens = (int) ($response->usage->promptTokens ?? 0);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);

        return $verdict->withCost(
            $promptTokens + $completionTokens,
            CostCalculator::completionMicros((string) ($response->meta->model ?? ''), $promptTokens, $completionTokens),
        );
    }

    private function prompt(string $answer): string
    {
        return "ANSWER TO GRADE:\n".Str::limit($answer, self::MAX_ANSWER_CHARACTERS);
    }
}
