<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedLibrary(): array
{
    $first = TestBook::create(['title' => 'Assedio della citta']);
    $first->pages()->create([
        'number' => 1,
        'content' => 'Il podesta convoco il consiglio. Le mura furono rinforzate e le porte sbarrate. La popolazione si rifugio nella rocca.',
    ]);
    $first->pages()->create([
        'number' => 2,
        'content' => 'Il grano fu razionato per tutto inverno. I mercanti protestarono a lungo davanti al palazzo comunale.',
    ]);

    $second = TestBook::create(['title' => 'Statuti agrari']);
    $second->pages()->create([
        'number' => 1,
        'content' => 'La rotazione delle colture era regolata dagli statuti. I contadini pagavano un decimo del raccolto.',
    ]);

    FilamentAi::ingestSync('books');

    return [$first, $second];
}

it('retrieves passages for a query', function (): void {
    seedLibrary();

    $result = app(Retriever::class)->retrieve('mura e porte della citta');

    expect($result->isEmpty())->toBeFalse()
        ->and($result->chunks->first())->toBeInstanceOf(ScoredChunk::class)
        ->and($result->timings)->toHaveKey('search_ms');
});

it('restricts retrieval to a single document', function (): void {
    [, $second] = seedLibrary();

    $result = app(Retriever::class)->retrieve('raccolto', new RetrievalOptions(
        externalIds: [(string) $second->id],
    ));

    foreach ($result->chunks as $chunk) {
        expect($chunk->externalId)->toBe((string) $second->id);
    }
});

it('restricts retrieval to a page range', function (): void {
    seedLibrary();

    $result = app(Retriever::class)->retrieve('grano', new RetrievalOptions(
        positionFrom: 2,
        positionTo: 2,
    ));

    foreach ($result->chunks as $chunk) {
        // Overlap test, not containment: a chunk spanning 1-2 is still on page 2.
        expect($chunk->positionEnd)->toBeGreaterThanOrEqual(2)
            ->and($chunk->positionStart)->toBeLessThanOrEqual(2);
    }
});

it('honours a source filter', function (): void {
    seedLibrary();

    $result = app(Retriever::class)->retrieve('statuti', new RetrievalOptions(
        sourceKeys: ['books'],
    ));

    expect($result->isEmpty())->toBeFalse();

    $none = app(Retriever::class)->retrieve('statuti', new RetrievalOptions(
        sourceKeys: ['does-not-exist'],
    ));

    expect($none->isEmpty())->toBeTrue();
});

it('accepts an arbitrary Eloquent constraint as an escape hatch', function (): void {
    seedLibrary();

    $documentId = Document::query()->where('title', 'Statuti agrari')->value('id');

    $result = app(Retriever::class)->retrieve('colture', new RetrievalOptions(
        constrain: fn ($query) => $query->where('c.document_id', $documentId),
    ));

    foreach ($result->chunks as $chunk) {
        expect($chunk->documentId)->toBe((int) $documentId);
    }
});

it('returns nothing above an impossible score threshold', function (): void {
    seedLibrary();

    $result = app(Retriever::class)->retrieve('qualunque cosa', new RetrievalOptions(
        minScore: 0.999,
    ));

    expect($result->isEmpty())->toBeTrue();
});

it('never leaks vectors into the result by default', function (): void {
    seedLibrary();

    $result = app(Retriever::class)->retrieve('mura');

    foreach ($result->chunks as $chunk) {
        expect($chunk->vector)->toBeNull();
    }
});

it('caps the result at top_k', function (): void {
    seedLibrary();

    $result = app(Retriever::class)->retrieve('citta', new RetrievalOptions(topK: 1));

    expect($result->chunks)->toHaveCount(1);
});
