<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval;

use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Embeddings\VectorMath;

/**
 * Maximal Marginal Relevance re-ranking.
 *
 * Pure similarity ranking tends to return eight paraphrases of the same
 * passage, which wastes the context window and makes the answer no better than
 * one chunk would have. MMR trades a little relevance for coverage:
 *
 *     score = lambda * sim(query, candidate)
 *           - (1 - lambda) * max sim(candidate, already_selected)
 *
 * lambda = 1 reduces to plain relevance; lambda = 0 maximises diversity alone.
 */
final class Mmr
{
    /**
     * @param  Collection<int, ScoredChunk>  $candidates  must carry vectors
     * @param  array<int, float>  $queryVector
     * @return Collection<int, ScoredChunk>
     */
    public function rerank(Collection $candidates, array $queryVector, float $lambda, int $limit): Collection
    {
        if ($candidates->count() <= 1 || $limit <= 1) {
            return $candidates->take($limit)->values();
        }

        /** @var array<int, ScoredChunk> $pool */
        $pool = $candidates->values()->all();

        // Without vectors there is nothing to diversify against; fall back to
        // the relevance order rather than silently returning something wrong.
        if ($pool[0]->vector === null) {
            return $candidates->take($limit)->values();
        }

        $selected = [];
        $selectedVectors = [];

        while (count($selected) < $limit && $pool !== []) {
            $bestIndex = null;
            $bestScore = -INF;

            foreach ($pool as $index => $candidate) {
                $redundancy = 0.0;

                foreach ($selectedVectors as $vector) {
                    $similarity = VectorMath::dot($candidate->vector ?? [], $vector);

                    if ($similarity > $redundancy) {
                        $redundancy = $similarity;
                    }
                }

                $score = $lambda * $candidate->score - (1 - $lambda) * $redundancy;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $chosen = $pool[$bestIndex];
            unset($pool[$bestIndex]);

            $selected[] = $chosen;
            $selectedVectors[] = $chosen->vector ?? [];
        }

        return collect($selected);
    }
}
