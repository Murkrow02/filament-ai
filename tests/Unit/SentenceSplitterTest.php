<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Chunking\HeuristicTokenEstimator;
use Murkrow\FilamentAi\Chunking\Normalizers\CollapseWhitespace;
use Murkrow\FilamentAi\Chunking\Normalizers\DehyphenateLineBreaks;
use Murkrow\FilamentAi\Chunking\Normalizers\FixOcrLigatures;
use Murkrow\FilamentAi\Chunking\Normalizers\NormalizerPipeline;
use Murkrow\FilamentAi\Chunking\Normalizers\StripControlChars;
use Murkrow\FilamentAi\Chunking\Sentence;
use Murkrow\FilamentAi\Chunking\SentenceSplitter;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Murkrow\FilamentAi\Data\Segment;

function splitter(): SentenceSplitter
{
    return new SentenceSplitter(
        new HeuristicTokenEstimator(4.0),
        new NormalizerPipeline([
            new StripControlChars,
            new FixOcrLigatures,
            new DehyphenateLineBreaks,
            new CollapseWhitespace,
        ]),
    );
}

function splitOptions(array $overrides = []): ChunkingOptions
{
    return ChunkingOptions::fromArray(array_merge([
        'sentence_regex' => '/(?<=[.!?])\s+(?=[A-Z0-9])/u',
        'hard_split_chars' => 240,
        'bridge_segments' => true,
        'chars_per_token' => 4.0,
        'normalizers' => [],
    ], $overrides));
}

/**
 * @return array<int, Sentence>
 */
function sentencesFor(array $pages, ?ChunkingOptions $options = null): array
{
    $segments = [];

    foreach ($pages as $index => $text) {
        $segments[] = new Segment($index + 1, $text);
    }

    return iterator_to_array(splitter()->split($segments, $options ?? splitOptions()));
}

it('splits on sentence boundaries', function (): void {
    $sentences = sentencesFor(['Prima frase. Seconda frase. Terza frase.']);

    expect($sentences)->toHaveCount(3)
        ->and($sentences[0]->text)->toBe('Prima frase.');
});

it('tags every sentence with its page', function (): void {
    $sentences = sentencesFor(['Uno. Due.', 'Tre. Quattro.']);

    expect($sentences[0]->position)->toBe(1)
        ->and(end($sentences)->position)->toBe(2);
});

it('bridges a sentence that continues on the next page', function (): void {
    $sentences = sentencesFor([
        'La citta era assediata e il consiglio decise che',
        'ogni porta sarebbe stata sbarrata. Cosi fu.',
    ]);

    $bridged = array_values(array_filter(
        $sentences,
        static fn (Sentence $s): bool => $s->positionEnd > $s->position,
    ));

    expect($bridged)->toHaveCount(1)
        ->and($bridged[0]->text)->toContain('decise che ogni porta')
        ->and($bridged[0]->position)->toBe(1)
        ->and($bridged[0]->positionEnd)->toBe(2);
});

it('does not bridge when the previous page ends a sentence', function (): void {
    $sentences = sentencesFor([
        'La citta era assediata.',
        'ogni porta era sbarrata.',
    ]);

    foreach ($sentences as $sentence) {
        expect($sentence->positionEnd)->toBe($sentence->position);
    }
});

it('does not bridge when the next page starts a new sentence', function (): void {
    $sentences = sentencesFor([
        'La citta era assediata e il consiglio decise che',
        'Poi tutto cambio.',
    ]);

    foreach ($sentences as $sentence) {
        expect($sentence->positionEnd)->toBe($sentence->position);
    }
});

it('hard splits text with no punctuation at all', function (): void {
    $sentences = sentencesFor([str_repeat('parola ', 200)], splitOptions(['hard_split_chars' => 100]));

    expect(count($sentences))->toBeGreaterThan(5);

    foreach ($sentences as $sentence) {
        expect(strlen($sentence->text))->toBeLessThanOrEqual(100);
    }
});

it('never loops forever on an unbroken run of characters', function (): void {
    $sentences = sentencesFor([str_repeat('a', 500)], splitOptions(['hard_split_chars' => 50]));

    expect(count($sentences))->toBe(10);
});

it('produces monotonically increasing offsets', function (): void {
    $sentences = sentencesFor(['Uno. Due. Tre.', 'Quattro. Cinque.']);

    $previous = -1;

    foreach ($sentences as $sentence) {
        expect($sentence->charStart)->toBeGreaterThan($previous);
        $previous = $sentence->charStart;
    }
});

it('skips a blank page without shifting the pages after it', function (): void {
    $sentences = sentencesFor(['Uno.', '   ', 'Tre.']);

    expect(array_map(static fn (Sentence $s): int => $s->position, $sentences))->toBe([1, 3]);
});
