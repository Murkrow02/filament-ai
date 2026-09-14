<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

use Illuminate\Support\Facades\Bus;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Events\IngestionRunFailed;
use Murkrow\FilamentAi\Events\IngestionRunStarted;
use Murkrow\FilamentAi\Jobs\FinalizeIngestionRunJob;
use Murkrow\FilamentAi\Jobs\PrepareDocumentJob;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Models\IngestionRunItem;
use Throwable;

/**
 * Plans a run and puts it on the queue.
 *
 * The two phases are separate batches rather than one: chunking is CPU-bound
 * and local, embedding is network-bound and rate-limited. Splitting them lets
 * the second phase be sized and throttled independently, and means a provider
 * outage never forces the chunking work to be redone.
 */
final class StartIngestionRun
{
    public function __construct(
        private readonly IngestionPlanner $planner,
        private readonly EmbeddingDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $chunkingOverrides
     */
    public function __invoke(
        KnowledgeSource $source,
        array $filters = [],
        IngestionMode $mode = IngestionMode::Incremental,
        array $chunkingOverrides = [],
        int|string|null $createdBy = null,
    ): IngestionRun {
        $run = $this->planner->plan($source, $filters, $mode, $chunkingOverrides, $createdBy);

        if ($createdBy !== null) {
            $run->forceFill(['created_by' => (string) $createdBy])->save();
        }

        IngestionRunStarted::dispatch($run);

        if ($run->documents_total === 0) {
            RunProgress::transition($run, RunStatus::Completed);

            return $run;
        }

        // Embeddings-only runs skip chunking entirely: nothing about the text
        // changed, only the vectors are missing or stale.
        if ($mode === IngestionMode::EmbeddingsOnly) {
            $this->attachExistingDocuments($run);
            RunProgress::transition($run, RunStatus::Embedding);
            $this->dispatcher->dispatchFor($run);

            return $run;
        }

        try {
            $runId = $run->id;

            $jobs = IngestionRunItem::query()
                ->where('run_id', $run->id)
                ->pluck('external_id')
                ->map(static fn (string $externalId): PrepareDocumentJob => new PrepareDocumentJob($runId, $externalId))
                ->all();

            $batch = Bus::batch($jobs)
                ->name("rag:chunk:{$run->uuid}")
                ->onConnection((string) config('rag.queue.connection'))
                ->onQueue((string) config('rag.queue.queue', 'rag'))
                ->allowFailures((bool) config('rag.queue.allow_failures', true))
                ->finally(static fn () => FinalizeIngestionRunJob::dispatch($runId))
                ->dispatch();

            $run->forceFill([
                'chunk_batch_id' => $batch->id,
                'status' => RunStatus::Chunking,
                'started_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            RunProgress::transition($run, RunStatus::Failed, $exception->getMessage());

            IngestionRunFailed::dispatch($run, $exception);

            throw $exception;
        }

        return $run;
    }

    /**
     * Link the run's work list to the documents that already exist, so the
     * embedding dispatcher can find their chunks.
     */
    private function attachExistingDocuments(IngestionRun $run): void
    {
        $documents = \Murkrow\FilamentAi\Models\Document::query()
            ->where('source_key', $run->source_key)
            ->pluck('id', 'external_id');

        IngestionRunItem::query()
            ->where('run_id', $run->id)
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($documents): void {
                foreach ($items as $item) {
                    $id = $documents[$item->external_id] ?? null;

                    if ($id !== null) {
                        $item->forceFill(['document_id' => $id])->save();
                    }
                }
            });
    }
}
