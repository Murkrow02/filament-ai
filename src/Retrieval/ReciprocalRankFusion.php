<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval;

/**
 * Fuses ranked result lists by rank rather than by score.
 *
 * Cosine similarity and BM25 are not on comparable scales, so blending the raw
 * numbers is meaningless. RRF only uses each item's position in its own list:
 *
 *     score = sum over lists of  weight / (k + rank)
 *
 * The lists can be any mix of legs: the vector and lexical results of the
 * question, and those of each rewrite of it when query expansion is on.
 */
final class ReciprocalRankFusion
{
    /**
     * @param  list<array{ids: array<int, int>, weight: float}>  $lists  each ordered by relevance
     * @return array<int, float> fused score keyed by chunk id, best first
     */
    public function fuse(array $lists, int $k): array
    {
        $scores = [];

        foreach ($lists as $list) {
            foreach (array_values($list['ids']) as $rank => $chunkId) {
                $scores[$chunkId] = ($scores[$chunkId] ?? 0.0) + $list['weight'] / ($k + $rank + 1);
            }
        }

        arsort($scores);

        return $scores;
    }
}
