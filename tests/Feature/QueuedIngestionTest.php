<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Enums\RunItemStatus;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Events\IngestionRunStarted;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Ingestion\StartIngestionRun;
use Murkrow\FilamentAi\Jobs\EmbedChunkGroupJob;
use Murkrow\FilamentAi\Jobs\PrepareDocumentJob;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/**
 * The queued path is the one production actually uses, and it is the one where
 * a mistake is least visible: a run that silently stops at 40% looks the same
 * as one still working.
 */
beforeEach(function (): void {
    // The real driver, not sync: see TestCase::createQueueTables().
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    config()->set('filament-ai.queue.connection', 'database');
    config()->set('filament-ai.queue.queue', 'rag');

    $this->createQueueTables();
});

/**
 * Queue a run and let the workers carry it to completion.
 */
function runQueued(array $filters = [], ?IngestionMode $mode = null, array $overrides = []): IngestionRun
{
    $run = FilamentAi::ingest('books', $filters, $mode ?? IngestionMode::Incremental, $overrides);

    test()->drainQueue();

    return $run->refresh();
}

function seedQueuedLibrary(int $books = 2, int $pages = 3): void
{
    for ($b = 1; $b <= $books; $b++) {
        $book = TestBook::create(['title' => "Volume {$b}"]);

        for ($p = 1; $p <= $pages; $p++) {
            $sentences = [];

            for ($i = 1; $i <= 8; $i++) {
                $sentences[] = "Volume {$b} pagina {$p} frase {$i}: il consiglio delibero in merito alla questione sollevata.";
            }

            $book->pages()->create(['number' => $p, 'content' => implode(' ', $sentences)]);
        }
    }
}

it('drives a run through both batches to completion', function (): void {
    seedQueuedLibrary();

    $run = runQueued();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->documents_total)->toBe(2)
        ->and($run->documents_done)->toBe(2)
        ->and($run->chunks_total)->toBeGreaterThan(0)
        ->and($run->chunks_embedded)->toBe($run->chunks_total)
        ->and($run->chunk_batch_id)->not->toBeNull()
        ->and($run->embed_batch_id)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();

    expect(Chunk::query()->whereNull('embedded_at')->count())->toBe(0)
        ->and(Document::query()->count())->toBe(2);
});

it('records a work item per document', function (): void {
    seedQueuedLibrary(books: 3, pages: 1);

    $run = runQueued();

    expect($run->items()->count())->toBe(3)
        ->and($run->items()->where('status', RunItemStatus::Chunked->value)->count())->toBe(3)
        ->and($run->items()->whereNotNull('document_id')->count())->toBe(3);
});

it('freezes the parameters it was launched with', function (): void {
    seedQueuedLibrary(books: 1, pages: 1);

    $run = runQueued(overrides: ['target_tokens' => 128]);

    expect($run->chunking_params['target_tokens'])->toBe(128)
        ->and($run->embedding_model)->toBe(config('filament-ai.embeddings.model'))
        ->and($run->embedding_dimensions)->toBe((int) config('filament-ai.embeddings.dimensions'))
        ->and($run->vector_driver)->toBe('memory');

    // A later config change must not retroactively alter the run's record.
    config()->set('filament-ai.chunking.target_tokens', 999);

    expect($run->refresh()->chunking_params['target_tokens'])->toBe(128);
});

it('queues the two phases as separate batches', function (): void {
    seedQueuedLibrary(books: 1, pages: 2);

    Bus::fake();

    $run = app(StartIngestionRun::class)(app(SourceRegistry::class)->get('books'));

    Bus::assertBatched(function ($batch) use ($run): bool {
        return $batch->name === "ai:chunk:{$run->uuid}"
            && $batch->jobs->every(fn ($job): bool => $job instanceof PrepareDocumentJob);
    });

    expect($run->refresh()->status)->toBe(RunStatus::Chunking);
});

it('completes immediately when no document matches', function (): void {
    $run = runQueued(['ids' => '999999']);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->documents_total)->toBe(0)
        ->and($run->chunks_total)->toBe(0);
});

it('announces that a run started', function (): void {
    seedQueuedLibrary(books: 1, pages: 1);

    Event::fake([IngestionRunStarted::class]);

    FilamentAi::ingest('books');

    Event::assertDispatched(IngestionRunStarted::class);
});

it('embeds nothing new on an unchanged incremental re-run', function (): void {
    seedQueuedLibrary(books: 1, pages: 2);

    runQueued();

    $second = runQueued(mode: IngestionMode::Incremental);

    expect($second->status)->toBe(RunStatus::Completed)
        ->and($second->documents_skipped)->toBe(1)
        ->and($second->chunks_total)->toBe(0)
        ->and($second->chunks_embedded)->toBe(0);
});

it('re-embeds without re-chunking in embeddings-only mode', function (): void {
    seedQueuedLibrary(books: 1, pages: 2);

    runQueued();

    $chunksBefore = Chunk::query()->pluck('content_hash')->sort()->values()->all();

    // Simulate vectors lost to a model change or a purge.
    Chunk::query()->update([
        'embedded_at' => null,
        'embedding_model' => null,
        'embedding_dimensions' => null,
    ]);

    $run = runQueued(mode: IngestionMode::EmbeddingsOnly);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->chunks_created)->toBe(0)
        ->and($run->chunks_embedded)->toBeGreaterThan(0)
        ->and(Chunk::query()->whereNull('embedded_at')->count())->toBe(0)
        ->and(Chunk::query()->pluck('content_hash')->sort()->values()->all())->toBe($chunksBefore);
});

it('accounts tokens and cost as it goes', function (): void {
    seedQueuedLibrary(books: 1, pages: 2);

    config()->set('filament-ai.embeddings.pricing.fake-embedding', 1.0);

    $run = runQueued();

    expect($run->tokens_used)->toBeGreaterThan(0)
        ->and($run->cost_micros)->toBeGreaterThan(0)
        ->and($run->api_calls)->toBeGreaterThan(0);
});

it('marks a document failed without taking the whole run down', function (): void {
    seedQueuedLibrary(books: 2, pages: 1);

    // A document that vanishes between planning and processing is the ordinary
    // race this has to survive.
    $run = app(StartIngestionRun::class);

    $source = app(SourceRegistry::class)->get('books');
    $ingestion = null;

    Bus::fake();
    $ingestion = $run($source);
    Bus::assertBatchCount(1);

    // Run one job by hand against a document that no longer exists.
    $job = new PrepareDocumentJob($ingestion->id, '999999');

    expect(fn () => $job->handle(app(SourceRegistry::class), app(\Murkrow\FilamentAi\Ingestion\DocumentIngestor::class)))
        ->toThrow(\Murkrow\FilamentAi\Exceptions\IngestionException::class);

    expect($ingestion->refresh()->documents_failed)->toBe(1);
});

it('is idempotent when an embedding job is retried', function (): void {
    seedQueuedLibrary(books: 1, pages: 2);

    runQueued();

    $chunkIds = Chunk::query()->pluck('id')->map(intval(...))->all();
    $embeddedAt = Chunk::query()->orderBy('id')->pluck('embedded_at', 'id');

    // Replaying the same job must not re-embed, and so must not re-charge.
    (new EmbedChunkGroupJob($chunkIds))->handle(app(\Murkrow\FilamentAi\Ingestion\ChunkEmbedder::class));

    foreach (Chunk::query()->orderBy('id')->get() as $chunk) {
        expect($chunk->embedded_at->timestamp)->toBe($embeddedAt[$chunk->id]->timestamp);
    }
});

it('stops a cancelled run from doing further work', function (): void {
    seedQueuedLibrary(books: 1, pages: 1);

    Bus::fake();

    $run = app(StartIngestionRun::class)(app(SourceRegistry::class)->get('books'));
    $run->cancel();

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled)
        ->and($run->finished_at)->not->toBeNull();

    // The finalizer must not resurrect a cancelled run.
    (new \Murkrow\FilamentAi\Jobs\FinalizeIngestionRunJob($run->id))
        ->handle(app(\Murkrow\FilamentAi\Ingestion\EmbeddingDispatcher::class));

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled);
});

it('reports run progress as a percentage', function (): void {
    seedQueuedLibrary(books: 1, pages: 2);

    $run = runQueued();

    expect($run->progress())->toEqualWithDelta(1.0, 1e-9)
        ->and($run->progressPercent())->toBe(100);

    $queued = IngestionRun::query()->create([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'source_key' => 'books',
        'status' => RunStatus::Queued,
        'mode' => IngestionMode::Full,
        'embedding_model' => 'fake-embedding',
        'embedding_dimensions' => 64,
        'vector_driver' => 'memory',
    ]);

    expect($queued->progressPercent())->toBe(0);
});
