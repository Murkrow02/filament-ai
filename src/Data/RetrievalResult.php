<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

use Illuminate\Support\Collection;

/**
 * @property-read Collection<int, ScoredChunk> $chunks
 */
final readonly class RetrievalResult
{
    /**
     * @param  Collection<int, ScoredChunk>  $chunks
     * @param  array<string, int>  $timings  milliseconds per stage
     */
    public function __construct(
        public Collection $chunks,
        public string $query,
        public int $embeddingTokens = 0,
        public array $timings = [],
        public int $candidatesExamined = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->chunks->isEmpty();
    }

    public function topScore(): ?float
    {
        $first = $this->chunks->first();

        return $first?->score;
    }

    public function totalMs(): int
    {
        return (int) array_sum($this->timings);
    }
}
