<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\VectorStores;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Exceptions\KnowledgeDisabledException;

/**
 * The vector store of a host with no knowledge base.
 *
 * Supported everywhere and installs nothing, so the package's migrations run
 * on any database. Reads find nothing; writes throw, because an ingestion that
 * reached a store with no column would otherwise "succeed" and index nothing.
 */
final class NullVectorStore implements VectorStore
{
    public function name(): string
    {
        return 'null';
    }

    public function dimensions(): int
    {
        return (int) config('filament-ai.embeddings.dimensions', 0);
    }

    public function installSchema(Blueprint $table, int $dimensions): void {}

    public function installIndexes(int $dimensions): void {}

    public function dropIndexes(): void {}

    public function assertSupported(): void {}

    public function upsert(iterable $vectors, string $model, int $dimensions): void
    {
        throw new KnowledgeDisabledException;
    }

    public function forget(array $chunkIds): void {}

    public function search(VectorQuery $query): Collection
    {
        return new Collection;
    }

    public function read(array $chunkIds): array
    {
        return [];
    }

    public function countEmbedded(?string $sourceKey = null): int
    {
        return 0;
    }
}
