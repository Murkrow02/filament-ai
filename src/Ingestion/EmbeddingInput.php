<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

use Murkrow\FilamentAi\Models\Chunk;

/**
 * Rebuilds the exact text that was hashed into a chunk's content_hash.
 *
 * The provenance header is stored on the chunk rather than recomputed, so an
 * embedding job never has to know which run created the row or what chunking
 * parameters were in force at the time. That keeps re-embedding a stale corpus
 * a one-column query rather than an archaeology exercise.
 */
final class EmbeddingInput
{
    public static function for(Chunk $chunk): string
    {
        $header = $chunk->metadata['header'] ?? null;

        if ($header === null || $header === '') {
            return $chunk->content;
        }

        return $header."\n".$chunk->content;
    }
}
