<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Embeddings\VectorMath;
use Murkrow\FilamentAi\Retrieval\Deduplicator;
use Murkrow\FilamentAi\Retrieval\Mmr;

function scored(int $id, float $score, array $vector, ?string $hash = null): ScoredChunk
{
    return new ScoredChunk(
        chunkId: $id,
        documentId: 1,
        sourceKey: 'books',
        externalId: '1',
        documentTitle: 'A book',
        ordinal: $id,
        positionStart: $id,
        positionEnd: $id,
        content: "content {$id}",
        contentHash: $hash ?? hash('sha256', (string) $id),
        score: $score,
        vector: VectorMath::normalize($vector),
    );
}

it('prefers a slightly weaker but different passage over a near duplicate', function (): void {
    $query = VectorMath::normalize([1.0, 0.0, 0.0]);

    $candidates = collect([
        scored(1, 0.95, [1.0, 0.02, 0.0]),
        scored(2, 0.94, [1.0, 0.03, 0.0]),   // almost identical to #1
        scored(3, 0.70, [0.4, 0.9, 0.0]),    // genuinely different
    ]);

    $ranked = (new Mmr)->rerank($candidates, $query, 0.5, 2);

    expect($ranked->pluck('chunkId')->all())->toBe([1, 3]);
});

it('reduces to plain relevance when lambda is one', function (): void {
    $query = VectorMath::normalize([1.0, 0.0, 0.0]);

    $candidates = collect([
        scored(1, 0.95, [1.0, 0.02, 0.0]),
        scored(2, 0.94, [1.0, 0.03, 0.0]),
        scored(3, 0.70, [0.4, 0.9, 0.0]),
    ]);

    $ranked = (new Mmr)->rerank($candidates, $query, 1.0, 2);

    expect($ranked->pluck('chunkId')->all())->toBe([1, 2]);
});

it('falls back to relevance order when vectors are unavailable', function (): void {
    $candidates = collect([
        scored(1, 0.9, [1.0, 0.0])->withVector(null),
        scored(2, 0.8, [0.0, 1.0])->withVector(null),
    ]);

    $ranked = (new Mmr)->rerank($candidates, [1.0, 0.0], 0.5, 1);

    expect($ranked->pluck('chunkId')->all())->toBe([1]);
});

it('collapses passages with an identical hash', function (): void {
    $candidates = collect([
        scored(1, 0.9, [1.0, 0.0], 'same'),
        scored(2, 0.8, [0.0, 1.0], 'same'),
        scored(3, 0.7, [0.0, 1.0], 'other'),
    ]);

    $deduped = (new Deduplicator)->dedupe($candidates, 1.0);

    expect($deduped->pluck('chunkId')->all())->toBe([1, 3]);
});

it('collapses passages that are near-identical by vector', function (): void {
    $candidates = collect([
        scored(1, 0.9, [1.0, 0.0, 0.0]),
        scored(2, 0.8, [0.999, 0.01, 0.0]),
        scored(3, 0.7, [0.0, 1.0, 0.0]),
    ]);

    $deduped = (new Deduplicator)->dedupe($candidates, 0.97);

    expect($deduped->pluck('chunkId')->all())->toBe([1, 3]);
});
