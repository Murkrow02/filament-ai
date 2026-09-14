<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Embeddings\VectorMath;
use Murkrow\FilamentAi\Support\Tables;
use Murkrow\FilamentAi\VectorStores\AbstractVectorStore;

/**
 * Vector store for tests: vectors in a JSON column, cosine computed in PHP.
 *
 * Exists so the suite can run on SQLite. It is intentionally O(n) and has no
 * index -- correctness only, never a production path.
 */
final class InMemoryVectorStore extends AbstractVectorStore
{
    public function name(): string
    {
        return 'memory';
    }

    public function installSchema(Blueprint $table, int $dimensions): void
    {
        $table->text('embedding')->nullable();
    }

    public function installIndexes(int $dimensions): void
    {
        //
    }

    public function dropIndexes(): void
    {
        //
    }

    public function assertSupported(): void
    {
        //
    }

    public function upsert(iterable $vectors, string $model, int $dimensions): void
    {
        foreach ($vectors as $entry) {
            \Murkrow\FilamentAi\Models\Chunk::query()->whereKey($entry['id'])->update([
                'embedding' => json_encode($entry['vector']),
                'embedding_model' => $model,
                'embedding_dimensions' => $dimensions,
                'embedded_at' => now(),
            ]);
        }
    }

    public function forget(array $chunkIds): void
    {
        if ($chunkIds === []) {
            return;
        }

        \Murkrow\FilamentAi\Models\Chunk::query()->whereIn('id', $chunkIds)->update([
            'embedding' => null,
            'embedding_model' => null,
            'embedding_dimensions' => null,
            'embedded_at' => null,
        ]);
    }

    /**
     * @return Collection<int, ScoredChunk>
     */
    public function search(VectorQuery $query): Collection
    {
        $rows = $this->baseQuery($query)
            ->select([...$this->selectColumns(), 'c.embedding'])
            ->get();

        return collect($rows->all())
            ->map(function (object $row) use ($query): ScoredChunk {
                $vector = json_decode((string) $row->embedding, true) ?: [];

                return $this->toScoredChunk($row, VectorMath::dot($query->vector, $vector));
            })
            ->when(
                $query->minScore !== null,
                static fn (Collection $chunks): Collection => $chunks->filter(
                    static fn (ScoredChunk $c): bool => $c->score >= $query->minScore,
                ),
            )
            ->sortByDesc(static fn (ScoredChunk $c): float => $c->score)
            ->take($query->limit)
            ->values();
    }

    /**
     * @return array<int, array<int, float>>
     */
    public function read(array $chunkIds): array
    {
        if ($chunkIds === []) {
            return [];
        }

        $vectors = [];

        $rows = \Murkrow\FilamentAi\Models\Chunk::query()
            ->whereIn('id', $chunkIds)
            ->whereNotNull('embedding')
            ->get(['id', 'embedding']);

        foreach ($rows as $row) {
            $vectors[(int) $row->id] = json_decode((string) $row->getRawOriginal('embedding'), true) ?: [];
        }

        return $vectors;
    }

    public function countEmbedded(?string $sourceKey = null): int
    {
        return \Murkrow\FilamentAi\Models\Chunk::query()
            ->whereNotNull('embedded_at')
            ->when($sourceKey !== null, static fn ($q) => $q->where('source_key', $sourceKey))
            ->count();
    }
}
