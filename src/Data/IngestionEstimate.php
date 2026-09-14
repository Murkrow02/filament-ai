<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * Rough pre-flight estimate shown before an ingestion run is launched, so a
 * user never starts a five-figure-token job blind.
 */
final readonly class IngestionEstimate
{
    public function __construct(
        public int $documents,
        public int $segments,
        public int $tokens,
        public int $chunks,
        public int $costMicros,
        public bool $sampled = true,
    ) {}

    public function costUsd(): float
    {
        return $this->costMicros / 1_000_000;
    }
}
