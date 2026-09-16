<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval;

use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Data\ScoredChunk;

/**
 * Fuses the vector and lexical result lists by rank rather than by score.
 *
 * Cosine similarity and BM25 are not on comparable scales, so blending the raw
 * numbers is meaningless. RRF only uses each item's position in its own list:
 *
 *     score = sum over lists of  weight / (k + rank)
 */
final class ReciprocalRankFusion
{
    /**
     * @param  Collection<int, ScoredChunk>  $vectorHits  ordered by relevance
     * @param  array<int, int>  $lexicalChunkIds  ordered by relevance
     * @return Collection<int, ScoredChunk>
     */
    public function fuse(Collection $vectorHits, array $lexicalChunkIds, int $k, float $lexicalWeight): Collection
    {
        if ($lexicalChunkIds === []) {
            return $vectorHits;
        }

        $vectorWeight = 1.0 - $lexicalWeight;
        $scores = [];

        foreach ($vectorHits->values() as $rank => $chunk) {
            $scores[$chunk->chunkId] = ($scores[$chunk->chunkId] ?? 0.0)
                + $vectorWeight / ($k + $rank + 1);
        }

        foreach (array_values($lexicalChunkIds) as $rank => $chunkId) {
            $scores[$chunkId] = ($scores[$chunkId] ?? 0.0)
                + $lexicalWeight / ($k + $rank + 1);
        }

        // Only chunks the vector leg actually returned can be re-scored here;
        // lexical-only ids are handled by the caller, which decides whether to
        // hydrate them.
        return $vectorHits
            ->map(static fn (ScoredChunk $c): ScoredChunk => $c->withScore($scores[$c->chunkId] ?? $c->score))
            ->sortByDesc(static fn (ScoredChunk $c): float => $c->score)
            ->values();
    }
}
