<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval;

use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Embeddings\VectorMath;

/**
 * Collapses duplicate and near-duplicate hits.
 *
 * This is mandatory rather than a nicety: adjacent chunks deliberately share
 * `overlap_tokens` of text, so a passage sitting on a chunk boundary reliably
 * matches twice. Without collapsing, half the context window can be the same
 * paragraph twice over.
 */
final class Deduplicator
{
    /**
     * @param  Collection<int, ScoredChunk>  $chunks  ordered by score descending
     * @return Collection<int, ScoredChunk>
     */
    public function dedupe(Collection $chunks, float $threshold): Collection
    {
        $seenHashes = [];
        $kept = [];
        $keptVectors = [];

        foreach ($chunks as $chunk) {
            if (isset($seenHashes[$chunk->contentHash])) {
                continue;
            }

            if ($chunk->vector !== null && $threshold < 1.0) {
                foreach ($keptVectors as $vector) {
                    if (VectorMath::dot($chunk->vector, $vector) >= $threshold) {
                        continue 2;
                    }
                }
            }

            $seenHashes[$chunk->contentHash] = true;
            $kept[] = $chunk;

            if ($chunk->vector !== null) {
                $keptVectors[] = $chunk->vector;
            }
        }

        return collect($kept);
    }
}
