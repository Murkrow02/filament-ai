<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking;

use Generator;
use Murkrow\FilamentAi\Chunking\Normalizers\NormalizerPipeline;
use Murkrow\FilamentAi\Contracts\TokenEstimator;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Murkrow\FilamentAi\Data\Segment;

/**
 * Turns an ordered stream of segments into an ordered stream of sentences,
 * each tagged with the segment it came from and its offset in the virtual
 * (normalized, newline-joined) document.
 *
 * Two behaviours here exist specifically because the input is OCR text:
 *
 *  - Hard splitting. Badly scanned pages often contain no sentence punctuation
 *    at all, so a whole page arrives as one "sentence". Left alone it would
 *    exceed the chunk budget on its own and force a degenerate split, so any
 *    sentence longer than `hard_split_chars` is cut on whitespace.
 *
 *  - Bridging. A sentence cut in half by a page break would otherwise become
 *    two fragments, neither of which means anything on its own. When the tail
 *    of one segment does not look finished and the head of the next does not
 *    look like a beginning, the two are stitched into a single sentence that
 *    legitimately spans both pages -- the only sentence that ever does.
 */
final class SentenceSplitter
{
    public function __construct(
        private readonly TokenEstimator $estimator,
        private readonly NormalizerPipeline $normalizer,
    ) {}

    /**
     * Characters that mark a finished sentence. A tail ending in any of these
     * is never bridged into the next page.
     */
    private const TERMINATORS = ".!?\u{2026}:;\u{00BB}\"')]";

    /**
     * @param  iterable<int, Segment>  $segments
     * @return Generator<int, Sentence>
     */
    public function split(iterable $segments, ChunkingOptions $options): Generator
    {
        $offset = 0;
        $emittedAny = false;
        $carry = null;

        foreach ($segments as $segment) {
            $text = $this->normalizer->normalize($segment->text);

            if ($text === '') {
                // Empty pages produce no sentences and no offset, so page
                // numbering downstream stays exact.
                continue;
            }

            if ($emittedAny) {
                // The virtual document joins segments with a single newline.
                $offset++;
            }

            $sentences = $this->sentencesFor($text, $segment->position, $offset, $options);

            $offset += strlen($text);
            $emittedAny = true;

            if ($sentences === []) {
                continue;
            }

            if ($carry !== null) {
                if ($options->bridgeSegments && $this->shouldBridge($carry, $sentences[0])) {
                    $merged = $carry->mergedWith(
                        $sentences[0],
                        $this->estimator->count($carry->text.' '.$sentences[0]->text),
                    );
                    $sentences[0] = $merged;
                } else {
                    yield $carry;
                }

                $carry = null;
            }

            // Hold back the last sentence: the next segment may continue it.
            $carry = array_pop($sentences);

            foreach ($sentences as $sentence) {
                yield $sentence;
            }
        }

        if ($carry !== null) {
            yield $carry;
        }
    }

    /**
     * @return array<int, Sentence>
     */
    private function sentencesFor(string $text, int $position, int $base, ChunkingOptions $options): array
    {
        $pieces = preg_split(
            $options->sentenceRegex,
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE,
        );

        if ($pieces === false) {
            // A malformed custom regex must not silently drop a page.
            $pieces = [[$text, 0]];
        }

        $sentences = [];

        foreach ($pieces as [$piece, $pieceOffset]) {
            $piece = (string) $piece;
            $pieceOffset = (int) $pieceOffset;

            foreach ($this->hardSplit($piece, $options->hardSplitChars) as [$part, $partOffset]) {
                $part = trim($part);

                if ($part === '') {
                    continue;
                }

                $start = $base + $pieceOffset + $partOffset;

                $sentences[] = new Sentence(
                    position: $position,
                    positionEnd: $position,
                    text: $part,
                    charStart: $start,
                    charEnd: $start + strlen($part),
                    tokens: $this->estimator->count($part),
                );
            }
        }

        return $sentences;
    }

    /**
     * Split an over-long run of text on whitespace near each boundary.
     *
     * @return array<int, array{0: string, 1: int}> [text, offset within $text]
     */
    private function hardSplit(string $text, int $limit): array
    {
        if ($limit <= 0 || strlen($text) <= $limit) {
            return [[$text, 0]];
        }

        $parts = [];
        $cursor = 0;
        $length = strlen($text);

        while ($cursor < $length) {
            $remaining = $length - $cursor;

            if ($remaining <= $limit) {
                $parts[] = [substr($text, $cursor), $cursor];
                break;
            }

            $window = substr($text, $cursor, $limit);
            $breakAt = strrpos($window, ' ');

            // No whitespace in the whole window: cut at the limit rather than
            // looping forever on a single unbroken token.
            $take = $breakAt === false || $breakAt === 0 ? $limit : $breakAt;

            $parts[] = [substr($text, $cursor, $take), $cursor];
            $cursor += $take;

            // Skip the whitespace we broke on.
            while ($cursor < $length && $text[$cursor] === ' ') {
                $cursor++;
            }
        }

        return $parts;
    }

    /**
     * True when the tail of one page and the head of the next look like halves
     * of the same sentence.
     */
    private function shouldBridge(Sentence $tail, Sentence $head): bool
    {
        $lastChar = mb_substr($tail->text, -1);

        if ($lastChar !== '' && str_contains(self::TERMINATORS, $lastChar)) {
            return false;
        }

        $firstChar = mb_substr($head->text, 0, 1);

        if ($firstChar === '') {
            return false;
        }

        // Lowercase or a digit means the next page picks up mid-sentence.
        return (bool) preg_match('/^[\p{Ll}0-9]/u', $firstChar);
    }
}
