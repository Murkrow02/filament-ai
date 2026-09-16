<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Embeddings\FakeEmbeddingProvider;
use Murkrow\FilamentAi\Embeddings\VectorMath;
use Murkrow\FilamentAi\Ingestion\ChunkEmbedder;
use Murkrow\FilamentAi\Facades\Rag;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Support\Tables;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;
use Illuminate\Support\Facades\DB;

function seedPgLibrary(): TestBook
{
    $book = TestBook::create(['title' => 'Cronaca dell assedio']);

    $book->pages()->create([
        'number' => 1,
        'content' => 'Il podesta convoco il consiglio generale. Le mura furono rinforzate e le porte sbarrate al tramonto. La popolazione si rifugio nella rocca centrale.',
    ]);
    $book->pages()->create([
        'number' => 2,
        'content' => 'Il grano venne razionato per tutto inverno successivo. I mercanti protestarono a lungo davanti al palazzo comunale della citta.',
    ]);

    Rag::ingestSync('books');

    return $book;
}

it('installs a real vector column of the configured width', function (): void {
    $column = DB::selectOne(
        'SELECT atttypmod FROM pg_attribute
         WHERE attrelid = ?::regclass AND attname = ?',
        [Tables::chunks(), 'embedding'],
    );

    expect($column)->not->toBeNull()
        ->and((int) $column->atttypmod)->toBe((int) config('rag.embeddings.dimensions'));
});

it('builds an HNSW index on the embedding column', function (): void {
    $index = DB::selectOne(
        'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE ?',
        [Tables::chunks(), '%USING hnsw%'],
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('vector_cosine_ops');
});

it('stores vectors that postgres can read back', function (): void {
    seedPgLibrary();

    $chunk = Chunk::query()->whereNotNull('embedded_at')->firstOrFail();

    $vectors = app(VectorStore::class)->read([(int) $chunk->id]);

    expect($vectors)->toHaveKey($chunk->id)
        ->and($vectors[$chunk->id])->toHaveCount((int) config('rag.embeddings.dimensions'));

    // Written normalised, so the norm must come back as 1.
    $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vectors[$chunk->id])));

    expect($norm)->toEqualWithDelta(1.0, 1e-5);
});

it('ranks with the cosine operator and returns a usable score', function (): void {
    seedPgLibrary();

    $vector = app(EmbeddingProvider::class)->embedQuery('le mura e le porte della citta');

    $hits = app(VectorStore::class)->search(new VectorQuery(vector: $vector, limit: 5));

    expect($hits)->not->toBeEmpty();

    $scores = $hits->pluck('score')->all();

    expect($scores)->toBe(collect($scores)->sortDesc()->values()->all());

    foreach ($scores as $score) {
        expect($score)->toBeGreaterThanOrEqual(-1.0)->toBeLessThanOrEqual(1.0);
    }
});

it('agrees with cosine similarity computed in php', function (): void {
    seedPgLibrary();

    $vector = app(EmbeddingProvider::class)->embedQuery('il grano razionato');

    $hit = app(VectorStore::class)->search(new VectorQuery(vector: $vector, limit: 1))->first();

    $stored = app(VectorStore::class)->read([$hit->chunkId])[$hit->chunkId];

    expect($hit->score)->toEqualWithDelta(VectorMath::dot($vector, $stored), 1e-5);
});

it('applies filters inside the sql, not after ranking', function (): void {
    $book = seedPgLibrary();

    $vector = app(EmbeddingProvider::class)->embedQuery('citta');

    $hits = app(VectorStore::class)->search(new VectorQuery(
        vector: $vector,
        limit: 10,
        positionFrom: 2,
        positionTo: 2,
    ));

    expect($hits)->not->toBeEmpty();

    foreach ($hits as $hit) {
        expect($hit->positionEnd)->toBeGreaterThanOrEqual(2)
            ->and($hit->positionStart)->toBeLessThanOrEqual(2)
            ->and($hit->externalId)->toBe((string) $book->id);
    }
});

it('drops a vector without deleting its chunk', function (): void {
    seedPgLibrary();

    $chunk = Chunk::query()->whereNotNull('embedded_at')->firstOrFail();

    app(VectorStore::class)->forget([(int) $chunk->id]);

    $chunk->refresh();

    expect($chunk->exists)->toBeTrue()
        ->and($chunk->embedded_at)->toBeNull()
        ->and(app(VectorStore::class)->read([(int) $chunk->id]))->toBe([]);
});

it('rebuilds the index on demand', function (): void {
    seedPgLibrary();

    $store = app(VectorStore::class);

    $store->dropIndexes();

    expect(DB::selectOne(
        'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE ?',
        [Tables::chunks(), '%USING hnsw%'],
    ))->toBeNull();

    $store->installIndexes($store->dimensions());

    expect(DB::selectOne(
        'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE ?',
        [Tables::chunks(), '%USING hnsw%'],
    ))->not->toBeNull();
});

function pgColumnWidth(): int
{
    return (int) DB::selectOne(
        'SELECT atttypmod FROM pg_attribute WHERE attrelid = ?::regclass AND attname = ?',
        [Tables::chunks(), 'embedding'],
    )->atttypmod;
}

it('reports the width the column was installed with', function (): void {
    expect(app(VectorStore::class)->installedDimensions())
        ->toBe((int) config('rag.embeddings.dimensions'));
});

it('keeps every vector when reindexing at the same width', function (): void {
    seedPgLibrary();

    $embedded = app(VectorStore::class)->countEmbedded();

    $this->artisan('rag:vector:reindex', ['--force' => true])->assertExitCode(0);

    expect($embedded)->toBeGreaterThan(0)
        ->and(app(VectorStore::class)->countEmbedded())->toBe($embedded)
        ->and(pgColumnWidth())->toBe((int) config('rag.embeddings.dimensions'));
});

it('resizes the column when the configured dimensions changed', function (): void {
    seedPgLibrary();

    $installed = (int) config('rag.embeddings.dimensions');
    $configured = intdiv($installed, 2);

    // What happens in a real deployment: the migration ran with one width,
    // then the model changed and the config with it.
    config(['rag.embeddings.dimensions' => $configured]);

    $this->artisan('rag:vector:reindex', ['--force' => true])
        ->expectsOutputToContain('--mode=embeddings_only')
        ->assertExitCode(0);

    expect(pgColumnWidth())->toBe($configured)
        ->and(app(VectorStore::class)->installedDimensions())->toBe($configured)
        ->and(Chunk::query()->whereNotNull('embedded_at')->count())->toBe(0)
        ->and(Document::query()->where('embedded_chunk_count', '>', 0)->count())->toBe(0)
        ->and(DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE ?',
            [Tables::chunks(), '%USING hnsw%'],
        ))->not->toBeNull();

    // The new width actually accepts writes -- the failure that prompted this.
    $ids = Chunk::query()->pluck('id')->map(intval(...))->all();
    $embedder = new ChunkEmbedder(new FakeEmbeddingProvider(dimensions: $configured), app(VectorStore::class));

    expect($embedder->embed($ids)['embedded'])->toBe(count($ids))
        ->and(app(VectorStore::class)->countEmbedded())->toBe(count($ids));
});

it('does nothing when the resize is not confirmed', function (): void {
    seedPgLibrary();

    $installed = (int) config('rag.embeddings.dimensions');
    $embedded = app(VectorStore::class)->countEmbedded();

    config(['rag.embeddings.dimensions' => intdiv($installed, 2)]);

    $this->artisan('rag:vector:reindex')
        ->expectsConfirmation('Discard '.number_format($embedded).' stored vectors, resize the column and rebuild the index?', 'no')
        ->assertExitCode(0);

    expect(pgColumnWidth())->toBe($installed)
        ->and(app(VectorStore::class)->countEmbedded())->toBe($embedded);
});

it('runs the whole retrieval pipeline against postgres', function (): void {
    seedPgLibrary();

    $result = app(Retriever::class)->retrieve('mura e porte sbarrate', new RetrievalOptions(topK: 3));

    expect($result->isEmpty())->toBeFalse()
        ->and($result->chunks->count())->toBeLessThanOrEqual(3)
        ->and($result->chunks->first()->documentTitle)->toBe('Cronaca dell assedio');
});

it('counts embedded chunks', function (): void {
    seedPgLibrary();

    expect(app(VectorStore::class)->countEmbedded())
        ->toBe(Chunk::query()->whereNotNull('embedded_at')->count());
});
