<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Generator;
use Murkrow\FilamentAi\Data\ChunkDraft;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Murkrow\FilamentAi\Data\Segment;

interface Chunker
{
    /**
     * Must be pure: the same segments and options always produce byte-identical
     * chunks and hashes, otherwise incremental re-ingestion degenerates into a
     * full re-embed on every run.
     *
     * @param  iterable<int, Segment>  $segments  ordered by position ascending
     * @return Generator<int, ChunkDraft>
     */
    public function chunk(iterable $segments, ChunkingOptions $options, ?string $documentTitle = null, ?callable $positionLabel = null): Generator;
}
