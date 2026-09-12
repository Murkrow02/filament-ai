<?php

declare(strict_types=1);

namespace Murkrow\Rag;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Murkrow\Rag\Contracts\Answerer;
use Murkrow\Rag\Contracts\KnowledgeSource;
use Murkrow\Rag\Contracts\Retriever;
use Murkrow\Rag\Data\AnswerOptions;
use Murkrow\Rag\Data\AnswerResult;
use Murkrow\Rag\Data\IngestionEstimate;
use Murkrow\Rag\Data\RetrievalOptions;
use Murkrow\Rag\Data\ScoredChunk;
use Murkrow\Rag\Enums\IngestionMode;
use Murkrow\Rag\Ingestion\IngestionPlanner;
use Murkrow\Rag\Ingestion\StartIngestionRun;
use Murkrow\Rag\Ingestion\SyncIngestionRunner;
use Murkrow\Rag\Models\Document;
use Murkrow\Rag\Models\IngestionRun;
use Murkrow\Rag\Sources\ClosureKnowledgeSource;
use Murkrow\Rag\Sources\SourceRegistry;

/**
 * The package's front door, exposed as the `Rag` facade.
 *
 * Everything here is a thin delegation: the useful behaviour lives in the
 * services, and this exists so application code has one obvious entry point
 * instead of five container bindings to remember.
 */
final class RagManager
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly Retriever $retriever,
        private readonly Answerer $answerer,
        private readonly StartIngestionRun $startRun,
        private readonly SyncIngestionRunner $syncRunner,
        private readonly IngestionPlanner $planner,
    ) {}

    /**
     * @return Collection<int, ScoredChunk>
     */
    public function search(string $question, RetrievalOptions $options = new RetrievalOptions): Collection
    {
        return $this->retriever->retrieve($question, $options)->chunks;
    }

    public function retrieve(string $question, RetrievalOptions $options = new RetrievalOptions): \Murkrow\Rag\Data\RetrievalResult
    {
        return $this->retriever->retrieve($question, $options);
    }

    public function ask(string $question, AnswerOptions $options = new AnswerOptions): AnswerResult
    {
        return $this->answerer->answer($question, $options);
    }

    /**
     * @return Generator<int, string, mixed, AnswerResult>
     */
    public function stream(string $question, AnswerOptions $options = new AnswerOptions): Generator
    {
        return $this->answerer->stream($question, $options);
    }

    /**
     * Queue an ingestion run.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $chunkingOverrides
     */
    public function ingest(
        string $sourceKey,
        array $filters = [],
        IngestionMode $mode = IngestionMode::Incremental,
        array $chunkingOverrides = [],
        int|string|null $createdBy = null,
    ): IngestionRun {
        return ($this->startRun)(
            $this->sources->get($sourceKey),
            $filters,
            $mode,
            $chunkingOverrides,
            $createdBy,
        );
    }

    /**
     * Run an ingestion in-process.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $chunkingOverrides
     * @param  Closure(string, int, int): void|null  $onProgress
     */
    public function ingestSync(
        string $sourceKey,
        array $filters = [],
        IngestionMode $mode = IngestionMode::Incremental,
        array $chunkingOverrides = [],
        ?Closure $onProgress = null,
    ): IngestionRun {
        return $this->syncRunner->run(
            $this->sources->get($sourceKey),
            $filters,
            $mode,
            $chunkingOverrides,
            $onProgress,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function estimate(string $sourceKey, array $filters = []): IngestionEstimate
    {
        return $this->planner->estimate($this->sources->get($sourceKey), $filters);
    }

    /**
     * Build a source at runtime, for knowledge that is not an Eloquent model.
     * Returns the builder; call `register()` on it once configured.
     */
    /**
     * Drop one indexed document, by the source key and external id it was
     * ingested under.
     *
     * For deletions in the host application: ingestion only ever walks what a
     * source still returns, so it cannot notice that a record is gone, and the
     * document keeps answering questions until something removes it. This is
     * the immediate, single-record counterpart to PruneOrphanChunksJob's
     * nightly sweep -- call it from a model observer's `deleted()`.
     *
     * Chunks and citations cascade from the document row and the vector lives
     * on the chunk row, so the cascade takes the embeddings with it.
     *
     * Note what `$externalId` means for a grouped source: the group, not the
     * host row. Deleting one of thousands of rows that share a document must
     * re-ingest that group instead of calling this.
     *
     * @return bool Whether a document was actually indexed under that id.
     */
    public function forget(string $sourceKey, string|int $externalId): bool
    {
        return Document::query()
            ->where('source_key', $sourceKey)
            ->where('external_id', (string) $externalId)
            ->delete() > 0;
    }

    public function source(string $key): ClosureKnowledgeSource
    {
        return new ClosureKnowledgeSource($key, $this->sources->register(...));
    }

    public function register(KnowledgeSource $source): void
    {
        $this->sources->register($source);
    }

    public function sources(): SourceRegistry
    {
        return $this->sources;
    }
}
