<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval;

use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;

/**
 * Pulls the chunks immediately before and after each hit.
 *
 * Useful when answers depend on continuous prose -- a definition introduced one
 * chunk earlier, a list continued in the next. Off by default because it
 * multiplies context size by (2n + 1).
 */
final class NeighborExpander
{
    /**
     * @param  Collection<int, ScoredChunk>  $hits
     * @return Collection<int, ScoredChunk>
     */
    public function expand(Collection $hits, int $radius): Collection
    {
        if ($radius <= 0 || $hits->isEmpty()) {
            return $hits;
        }

        $wanted = [];

        foreach ($hits as $hit) {
            for ($offset = -$radius; $offset <= $radius; $offset++) {
                if ($offset === 0) {
                    continue;
                }

                $ordinal = $hit->ordinal + $offset;

                if ($ordinal < 0) {
                    continue;
                }

                $wanted[$hit->documentId][$ordinal] = true;
            }
        }

        if ($wanted === []) {
            return $hits;
        }

        $query = Chunk::query();

        foreach ($wanted as $documentId => $ordinals) {
            $query->orWhere(function ($q) use ($documentId, $ordinals): void {
                $q->where('document_id', $documentId)
                    ->whereIn('ordinal', array_keys($ordinals));
            });
        }

        $existing = $hits->pluck('chunkId')->all();

        $neighbors = $query->whereNotIn('id', $existing ?: [0])->get();

        if ($neighbors->isEmpty()) {
            return $hits;
        }

        $documents = Document::query()
            ->whereIn('id', $neighbors->pluck('document_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $byDocument = $hits->keyBy('chunkId');

        foreach ($neighbors as $neighbor) {
            /** @var Document|null $document */
            $document = $documents->get($neighbor->document_id);

            // Neighbours inherit a slightly reduced score so they sort below
            // the hit that pulled them in, without displacing real matches.
            $anchor = $hits->firstWhere('documentId', $neighbor->document_id);

            $byDocument->put($neighbor->id, new ScoredChunk(
                chunkId: (int) $neighbor->id,
                documentId: (int) $neighbor->document_id,
                sourceKey: (string) $neighbor->source_key,
                externalId: (string) ($document?->external_id ?? ''),
                documentTitle: $document?->title,
                ordinal: (int) $neighbor->ordinal,
                positionStart: (int) $neighbor->position_start,
                positionEnd: (int) $neighbor->position_end,
                content: (string) $neighbor->content,
                contentHash: (string) $neighbor->content_hash,
                score: ($anchor?->score ?? 0.0) * 0.99,
                metadata: (array) ($neighbor->metadata ?? []),
            ));
        }

        return $byDocument->values()
            ->sortByDesc(static fn (ScoredChunk $c): float => $c->score)
            ->values();
    }
}
