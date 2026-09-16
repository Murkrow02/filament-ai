<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

/**
 * Where a document's ordered text lives.
 *
 * Either an ordered has-many relation -- a book's pages, an article's sections
 * -- or a single column on the model itself. The position is what ends up in
 * `position_start` / `position_end` and is rendered in citations, so it has to
 * be a number a human recognises (a page number, not a row id) whenever one
 * exists.
 */
final readonly class SegmentMap
{
    private function __construct(
        public ?string $relation,
        public string $text,
        public string $position,
        public string $orderBy,
        public int $batchSize,
    ) {}

    /**
     * Segments come from an ordered has-many relation on the model.
     */
    public static function relation(
        string $relation,
        string $text = 'content',
        string $position = 'id',
        ?string $orderBy = null,
        int $batchSize = 200,
    ): self {
        return new self($relation, $text, $position, $orderBy ?? $position, $batchSize);
    }

    /**
     * The model itself carries the whole text in one column: one segment.
     */
    public static function column(string $text): self
    {
        return new self(null, $text, 'id', 'id', 1);
    }

    public function isRelation(): bool
    {
        return $this->relation !== null;
    }
}
