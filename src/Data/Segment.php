<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * One ordered unit of source text, as produced by a KnowledgeSource.
 *
 * For a book this is a page; for an article it might be a section. The
 * `position` is what ends up in a chunk's position_start / position_end and is
 * what citations are rendered from, so it must be meaningful to a human.
 */
final readonly class Segment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $position,
        public string $text,
        public array $metadata = [],
    ) {}
}
