<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Answering\CitationParser;
use Murkrow\FilamentAi\Data\Citation;
use Murkrow\FilamentAi\Data\ScoredChunk;

function citation(int $marker): Citation
{
    return new Citation(
        marker: $marker,
        rank: $marker - 1,
        chunk: new ScoredChunk(
            chunkId: $marker,
            documentId: 1,
            sourceKey: 'books',
            externalId: '1',
            documentTitle: 'A book',
            ordinal: $marker,
            positionStart: $marker,
            positionEnd: $marker,
            content: 'text',
            contentHash: hash('sha256', (string) $marker),
            score: 0.5,
        ),
        label: "A book - Page {$marker}",
    );
}

it('finds the markers an answer used', function (): void {
    expect((new CitationParser)->markers('First [#1] and then [#3].'))->toBe([1, 3]);
});

it('marks only the citations that were referenced', function (): void {
    $citations = collect([citation(1), citation(2), citation(3)]);

    $marked = (new CitationParser)->markUsed('Grounded in [#2].', $citations);

    expect($marked->firstWhere('marker', 2)->used)->toBeTrue()
        ->and($marked->firstWhere('marker', 1)->used)->toBeFalse();
});

it('strips markers that point at nothing', function (): void {
    $citations = collect([citation(1), citation(2)]);

    $answer = (new CitationParser)->stripUnknownMarkers('Real [#1] and invented [#9].', $citations);

    expect($answer)->toContain('[#1]')->and($answer)->not->toContain('[#9]');
});

it('recognises an answer with no markers at all', function (): void {
    expect((new CitationParser)->hasAnyMarker('No sources here.'))->toBeFalse();
});
