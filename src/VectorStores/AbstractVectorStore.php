<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\VectorStores;

use Illuminate\Database\Eloquent\Builder;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Filter compilation shared by every driver.
 *
 * Only the ranking is driver-specific; which rows are eligible is ordinary SQL
 * and belongs in one place.
 */
abstract class AbstractVectorStore implements VectorStore
{
    /**
     * @return Builder<Chunk>
     */
    protected function baseQuery(VectorQuery $query): Builder
    {
        $chunks = Tables::chunks();
        $documents = Tables::documents();

        $builder = Chunk::query()
            ->from($chunks.' as c')
            ->join($documents.' as d', 'd.id', '=', 'c.document_id')
            ->whereNotNull('c.embedded_at');

        // An empty list means "nothing matches", never "no filter": an empty
        // MCP allow-list has to close the door, not open it. Laravel compiles
        // whereIn(column, []) to `0 = 1`, which is precisely that.
        if ($query->sourceKeys !== null) {
            $builder->whereIn('c.source_key', $query->sourceKeys);
        }

        if ($query->documentIds !== null) {
            $builder->whereIn('c.document_id', $query->documentIds);
        }

        if ($query->externalIds !== null) {
            $builder->whereIn('d.external_id', $query->externalIds);
        }

        // Overlap test, not containment: a chunk spanning pages 12-13 must be
        // returned for a filter of "page 13 only".
        if ($query->positionFrom !== null) {
            $builder->where('c.position_end', '>=', $query->positionFrom);
        }

        if ($query->positionTo !== null) {
            $builder->where('c.position_start', '<=', $query->positionTo);
        }

        if ($query->restrictToChunkIds !== null) {
            $builder->whereIn('c.id', $query->restrictToChunkIds);
        }

        if ($query->constrain !== null) {
            ($query->constrain)($builder);
        }

        return $builder;
    }

    /**
     * @return array<int, string>
     */
    protected function selectColumns(): array
    {
        return [
            'c.id as chunk_id',
            'c.document_id',
            'c.source_key',
            'c.ordinal',
            'c.position_start',
            'c.position_end',
            'c.content',
            'c.content_hash',
            'c.metadata',
            'd.external_id',
            'd.title',
        ];
    }

    protected function toScoredChunk(object $row, float $score): ScoredChunk
    {
        $metadata = $row->metadata ?? null;

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return new ScoredChunk(
            chunkId: (int) $row->chunk_id,
            documentId: (int) $row->document_id,
            sourceKey: (string) $row->source_key,
            externalId: (string) $row->external_id,
            documentTitle: $row->title === null ? null : (string) $row->title,
            ordinal: (int) $row->ordinal,
            positionStart: (int) $row->position_start,
            positionEnd: (int) $row->position_end,
            content: (string) $row->content,
            contentHash: (string) $row->content_hash,
            score: $score,
            metadata: is_array($metadata) ? $metadata : [],
        );
    }

    public function dimensions(): int
    {
        return (int) config('filament-ai.embeddings.dimensions', 1536);
    }
}
