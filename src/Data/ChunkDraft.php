<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * A chunk as the chunker produced it, before it is persisted.
 *
 * `contentHash` is the sha256 of `embeddingInput` (not of `text`), so that a
 * change to the document title -- which is part of the context header -- also
 * invalidates the stored vector. Incremental re-ingestion diffs on this hash.
 */
final readonly class ChunkDraft
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $ordinal,
        public string $text,
        public string $embeddingInput,
        public string $contentHash,
        public int $positionStart,
        public int $positionEnd,
        public int $charStart,
        public int $charEnd,
        public int $tokenCount,
        public ?string $header = null,
        public array $metadata = [],
    ) {}
}
