<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

use Murkrow\FilamentAi\Models\Document;

final readonly class DocumentIngestionResult
{
    public function __construct(
        public Document $document,
        public int $chunksCreated = 0,
        public int $chunksReused = 0,
        public int $chunksDeleted = 0,
        public int $tokens = 0,
        public bool $skipped = false,
        public int $durationMs = 0,
    ) {}

    public function chunkCount(): int
    {
        return $this->chunksCreated + $this->chunksReused;
    }
}
