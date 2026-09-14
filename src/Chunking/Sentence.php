<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking;

/**
 * Internal unit of the chunker: one sentence, tagged with the segment (page)
 * it belongs to and its offsets in the virtual document.
 *
 * `position` and `positionEnd` differ only for a bridged sentence -- one that
 * was cut in half by a page break and stitched back together. Every other
 * sentence belongs to exactly one page, which is what makes chunk page ranges
 * exact rather than estimated.
 *
 * Offsets are byte offsets into the normalized, newline-joined document. For
 * Latin-script text they are within a couple of percent of character offsets.
 */
final readonly class Sentence
{
    public function __construct(
        public int $position,
        public int $positionEnd,
        public string $text,
        public int $charStart,
        public int $charEnd,
        public int $tokens,
    ) {}

    public function mergedWith(self $next, int $tokens): self
    {
        return new self(
            position: $this->position,
            positionEnd: max($next->positionEnd, $this->positionEnd),
            text: $this->text.' '.$next->text,
            charStart: $this->charStart,
            charEnd: $next->charEnd,
            tokens: $tokens,
        );
    }
}
