<?php

declare(strict_types=1);

use Murkrow\Rag\Contracts\EmbeddingProvider;
use Murkrow\Rag\Contracts\VectorStore;
use Murkrow\Rag\Data\EmbeddingBatch;
use Murkrow\Rag\Embeddings\FakeEmbeddingProvider;
use Murkrow\Rag\Facades\Rag;
use Murkrow\Rag\Ingestion\ChunkEmbedder;
use Murkrow\Rag\Models\Chunk;
use Murkrow\Rag\Models\Document;
use Murkrow\Rag\Tests\Fixtures\TestBook;

/**
 * Records the size of every embedBatch() call, and can fail on a given one.
 */
final class RecordingEmbeddingProvider implements EmbeddingProvider
{
    /** @var array<int, int> */
    public array $calls = [];

    private readonly FakeEmbeddingProvider $inner;

    public function __construct(private readonly int $batchSize, private readonly ?int $failOnCall = null)
    {
        $this->inner = new FakeEmbeddingProvider(
            dimensions: (int) config('rag.embeddings.dimensions'),
            model: (string) config('rag.embeddings.model'),
            batchSize: $batchSize,
        );
    }

    public function embedBatch(array $texts): EmbeddingBatch
    {
        $this->calls[] = count($texts);

        if ($this->failOnCall === count($this->calls)) {
            throw new RuntimeException('The embedding provider went away.');
        }

        return $this->inner->embedBatch($texts);
    }

    public function embedQuery(string $text): array
    {
        return $this->inner->embedQuery($text);
    }

    public function model(): string
    {
        return $this->inner->model();
    }

    public function dimensions(): int
    {
        return $this->inner->dimensions();
    }

    public function maxBatchSize(): int
    {
        return $this->batchSize;
    }

    public function maxInputTokens(): int
    {
        return $this->inner->maxInputTokens();
    }
}

/**
 * A book chunked into several small chunks, with every vector cleared again.
 *
 * @return array<int, int>
 */
function chunkedBookWithoutVectors(): array
{
    config([
        'rag.chunking.target_tokens' => 64,
        'rag.chunking.max_tokens' => 96,
        'rag.chunking.min_tokens' => 16,
        'rag.chunking.overlap_tokens' => 0,
    ]);

    $book = TestBook::create(['title' => 'Cronaca in piccoli pezzi']);

    for ($page = 1; $page <= 4; $page++) {
        $sentences = [];

        for ($i = 1; $i <= 6; $i++) {
            $sentences[] = "Pagina {$page} frase {$i}: il consiglio delibero sulla strada che porta alla marina.";
        }

        $book->pages()->create(['number' => $page, 'content' => implode(' ', $sentences)]);
    }

    Rag::ingestSync('books');

    $ids = Chunk::query()->orderBy('id')->pluck('id')->map(intval(...))->all();

    app(VectorStore::class)->forget($ids);

    return $ids;
}

it('sends a group in calls no larger than the provider batch size', function (): void {
    $ids = chunkedBookWithoutVectors();
    expect(count($ids))->toBeGreaterThanOrEqual(5);

    $provider = new RecordingEmbeddingProvider(batchSize: 2);

    $result = (new ChunkEmbedder($provider, app(VectorStore::class)))->embed($ids);

    expect($provider->calls)->each->toBeLessThanOrEqual(2)
        ->and(array_sum($provider->calls))->toBe(count($ids))
        ->and(count($provider->calls))->toBe((int) ceil(count($ids) / 2))
        ->and($result['embedded'])->toBe(count($ids))
        ->and($result['tokens'])->toBeGreaterThan(0)
        ->and(Chunk::query()->whereNotNull('embedded_at')->count())->toBe(count($ids));

    $document = Document::query()->firstOrFail();

    expect($document->embedded_chunk_count)->toBe($document->chunk_count);
});

it('makes a single call when the group fits in one batch', function (): void {
    $ids = chunkedBookWithoutVectors();

    $provider = new RecordingEmbeddingProvider(batchSize: 96);

    (new ChunkEmbedder($provider, app(VectorStore::class)))->embed($ids);

    expect($provider->calls)->toBe([count($ids)]);
});

it('writes nothing when a later call fails, so a retry stays exact', function (): void {
    $ids = chunkedBookWithoutVectors();

    $failing = new RecordingEmbeddingProvider(batchSize: 2, failOnCall: 2);

    expect(fn () => (new ChunkEmbedder($failing, app(VectorStore::class)))->embed($ids))
        ->toThrow(RuntimeException::class);

    // The first call succeeded, but none of it was written: a half-written
    // group would be skipped by the retry and never counted in the run.
    expect(Chunk::query()->whereNotNull('embedded_at')->count())->toBe(0);

    $retry = (new ChunkEmbedder(new RecordingEmbeddingProvider(batchSize: 2), app(VectorStore::class)))->embed($ids);

    expect($retry['embedded'])->toBe(count($ids))
        ->and(Chunk::query()->whereNotNull('embedded_at')->count())->toBe(count($ids));
});
