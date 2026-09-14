<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Chunking\Normalizers\CollapseWhitespace;
use Murkrow\FilamentAi\Chunking\Normalizers\DehyphenateLineBreaks;
use Murkrow\FilamentAi\Chunking\Normalizers\FixOcrLigatures;
use Murkrow\FilamentAi\Chunking\Normalizers\StripControlChars;
use Murkrow\FilamentAi\Chunking\SlidingWindowChunker;
use Murkrow\FilamentAi\Chunking\TokenEstimatorFactory;
use Murkrow\FilamentAi\Data\ChunkDraft;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Murkrow\FilamentAi\Data\Segment;

/**
 * The chunker is the component whose bugs are hardest to notice: bad chunks do
 * not raise errors, they just quietly retrieve the wrong thing. These tests pin
 * the properties that everything downstream depends on.
 */
function chunkingOptions(array $overrides = []): ChunkingOptions
{
    return ChunkingOptions::fromArray(array_merge([
        'target_tokens' => 40,
        'max_tokens' => 70,
        'min_tokens' => 8,
        'overlap_tokens' => 12,
        'hard_split_chars' => 240,
        'bridge_segments' => true,
        'sentence_regex' => '/(?<=[.!?])\s+(?=[A-Z0-9])/u',
        'chars_per_token' => 4.0,
        'normalizers' => [
            StripControlChars::class,
            FixOcrLigatures::class,
            DehyphenateLineBreaks::class,
            CollapseWhitespace::class,
        ],
        'embed_context_header' => true,
        'context_header' => ':document_title - :position_label',
    ], $overrides));
}

function chunkerFor(): SlidingWindowChunker
{
    return new SlidingWindowChunker(new TokenEstimatorFactory);
}

/**
 * @param  array<int, string>  $pages
 * @return array<int, ChunkDraft>
 */
function chunkPages(array $pages, ?ChunkingOptions $options = null): array
{
    $segments = [];

    foreach ($pages as $index => $text) {
        $segments[] = new Segment($index + 1, $text);
    }

    return iterator_to_array(chunkerFor()->chunk(
        $segments,
        $options ?? chunkingOptions(),
        'Test document',
        static fn (int $from, int $to): string => $from === $to ? "Page {$from}" : "Pages {$from}-{$to}",
    ));
}

function longPage(int $sentences, string $word = 'parola'): string
{
    $out = [];

    for ($i = 1; $i <= $sentences; $i++) {
        $out[] = "Questa e la frase numero {$i} di prova con {$word} ripetuta a sufficienza.";
    }

    return implode(' ', $out);
}

it('emits chunks in ordinal order starting at zero', function (): void {
    $chunks = chunkPages([longPage(20), longPage(20)]);

    expect($chunks)->not->toBeEmpty()
        ->and(array_map(static fn (ChunkDraft $c): int => $c->ordinal, $chunks))
        ->toBe(range(0, count($chunks) - 1));
});

it('carries overlapping text from one chunk into the next', function (): void {
    $chunks = chunkPages([longPage(30)]);

    expect(count($chunks))->toBeGreaterThan(1);

    // The tail of one chunk must reappear at the head of the next, otherwise a
    // fact sitting on the boundary exists in neither.
    $first = $chunks[0];
    $second = $chunks[1];

    expect($second->charStart)->toBeLessThan($first->charEnd);
});

it('produces a chunk that spans two pages', function (): void {
    $chunks = chunkPages([longPage(6), longPage(6)]);

    $spanning = array_filter($chunks, static fn (ChunkDraft $c): bool => $c->positionEnd > $c->positionStart);

    expect($spanning)->not->toBeEmpty();
});

it('never reports a position range running backwards', function (): void {
    $chunks = chunkPages([longPage(10), longPage(3), longPage(8)]);

    foreach ($chunks as $chunk) {
        expect($chunk->positionEnd)->toBeGreaterThanOrEqual($chunk->positionStart);
    }
});

it('stitches a sentence cut in half by a page break', function (): void {
    $chunks = chunkPages([
        'Il podesta convoco il consiglio e dichiaro che la citta',
        'sarebbe stata difesa a ogni costo. Poi si ritiro.',
    ]);

    $text = implode(' ', array_map(static fn (ChunkDraft $c): string => $c->text, $chunks));

    expect($text)->toContain('la citta sarebbe stata difesa');
});

it('hard splits a page with no sentence punctuation', function (): void {
    $garbled = str_repeat('parolasenzapunteggiatura ', 200);

    $chunks = chunkPages([$garbled], chunkingOptions(['hard_split_chars' => 120]));

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect($chunk->tokenCount)->toBeLessThanOrEqual(120);
    }
});

it('merges a short trailing chunk into the previous one', function (): void {
    $chunks = chunkPages([longPage(12).' Fine.']);

    $last = end($chunks);

    expect($last->tokenCount)->toBeGreaterThanOrEqual(8);
});

it('is deterministic across runs', function (): void {
    $pages = [longPage(15), longPage(9)];

    $first = array_map(static fn (ChunkDraft $c): string => $c->contentHash, chunkPages($pages));
    $second = array_map(static fn (ChunkDraft $c): string => $c->contentHash, chunkPages($pages));

    expect($first)->toBe($second)->and($first)->not->toBeEmpty();
});

it('changes every hash when the document title changes', function (): void {
    $segments = [new Segment(1, longPage(5))];
    $options = chunkingOptions();

    $withTitle = iterator_to_array(chunkerFor()->chunk($segments, $options, 'Title A'));
    $withOther = iterator_to_array(chunkerFor()->chunk($segments, $options, 'Title B'));

    expect($withTitle[0]->contentHash)->not->toBe($withOther[0]->contentHash);
});

it('prefixes the embedding input with the provenance header', function (): void {
    $chunks = chunkPages([longPage(4)]);

    expect($chunks[0]->header)->toContain('Test document')
        ->and($chunks[0]->embeddingInput)->toStartWith($chunks[0]->header)
        ->and($chunks[0]->embeddingInput)->toContain($chunks[0]->text)
        ->and($chunks[0]->contentHash)->toBe(hash('sha256', $chunks[0]->embeddingInput));
});

it('does not emit a chunk for an empty document', function (): void {
    expect(chunkPages(['', '   ']))->toBeEmpty();
});

it('keeps page numbers honest when a page is blank', function (): void {
    $chunks = chunkPages([longPage(4), '', longPage(4)]);

    $positions = [];

    foreach ($chunks as $chunk) {
        $positions[] = $chunk->positionStart;
        $positions[] = $chunk->positionEnd;
    }

    // Page 2 is blank, so nothing may claim to come from it, and page 3 must
    // still be reachable.
    expect($positions)->not->toContain(2)->and($positions)->toContain(3);
});
