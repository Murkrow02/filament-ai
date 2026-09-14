<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Data\VectorQuery;

/**
 * A backend capable of storing and ranking embedding vectors.
 *
 * Vectors live on the chunks table keyed by chunk id, so swapping drivers is a
 * column migration rather than a re-embed.
 */
interface VectorStore
{
    public function name(): string;

    public function dimensions(): int;

    /**
     * Add this driver's vector columns to the chunks table. Called from the
     * `add_rag_vector_column` migration, which owns no schema of its own.
     */
    public function installSchema(Blueprint $table, int $dimensions): void;

    /**
     * Raw statements a Blueprint cannot express (CREATE INDEX ... USING hnsw).
     */
    public function installIndexes(int $dimensions): void;

    public function dropIndexes(): void;

    /**
     * Verify the connection can actually host this driver (right database
     * engine, extension present, ...). Throws on failure.
     */
    public function assertSupported(): void;

    /**
     * @param  iterable<int, array{id: int, vector: array<int, float>}>  $vectors
     */
    public function upsert(iterable $vectors, string $model, int $dimensions): void;

    /**
     * @param  array<int, int>  $chunkIds
     */
    public function forget(array $chunkIds): void;

    /**
     * @return Collection<int, ScoredChunk> ordered by score descending
     */
    public function search(VectorQuery $query): Collection;

    /**
     * Load raw vectors for the given chunks, keyed by chunk id.
     *
     * @param  array<int, int>  $chunkIds
     * @return array<int, array<int, float>>
     */
    public function read(array $chunkIds): array;

    public function countEmbedded(?string $sourceKey = null): int;
}
