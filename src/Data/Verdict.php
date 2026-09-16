<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * What a verifier made of one attempt.
 *
 * The reason travels with the score because it is used twice: shown to the
 * person reading the run, and handed to the next wave so the agent knows what
 * was wrong with the last answer. What the judgement itself cost is carried
 * too -- judging is not free, and a run's budget has to include it.
 */
final readonly class Verdict
{
    /**
     * @param  int  $score  0-100
     */
    public function __construct(
        public bool $accepted,
        public int $score = 0,
        public string $reason = '',
        public bool $judged = true,
        public int $tokens = 0,
        public int $costMicros = 0,
    ) {}

    public static function accept(int $score = 100, string $reason = ''): self
    {
        return new self(true, self::clamp($score), $reason);
    }

    public static function reject(int $score = 0, string $reason = ''): self
    {
        return new self(false, self::clamp($score), $reason);
    }

    /**
     * The verifier could not answer -- it errored, or its reply made no sense.
     * Neither accepted nor rejected: the attempt keeps its answer and is left
     * out of the ranking.
     */
    public static function unjudged(string $reason = ''): self
    {
        return new self(false, 0, $reason, judged: false);
    }

    public function withCost(int $tokens, int $costMicros): self
    {
        return new self($this->accepted, $this->score, $this->reason, $this->judged, $tokens, $costMicros);
    }

    private static function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
