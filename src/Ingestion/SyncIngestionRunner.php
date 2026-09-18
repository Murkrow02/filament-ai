<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

use Closure;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Murkrow\FilamentAi\Data\DocumentDraft;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Enums\RunItemStatus;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Models\IngestionRunItem;
use Throwable;

/**
 * Runs an ingestion in-process, without touching the queue.
 *
 * Exists for two situations that matter: proving the whole pipeline works on a
 * fresh install with nothing but `php artisan`, and small corpora where
 * standing up a worker is more ceremony than the job deserves. It reuses the
 * same ingestor and embedder as the queued path, so behaviour cannot diverge.
 */
final class SyncIngestionRunner
{
    public function __construct(
        private readonly IngestionPlanner $planner,
        private readonly DocumentIngestor $ingestor,
        private readonly ChunkEmbedder $embedder,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $chunkingOverrides
     * @param  Closure(string, int, int): void|null  $onProgress  (stage, done, total)
     */
    public function run(
        KnowledgeSource $source,
        array $filters = [],
        IngestionMode $mode = IngestionMode::Incremental,
        array $chunkingOverrides = [],
        ?Closure $onProgress = null,
    ): IngestionRun {
        $run = $this->planner->plan($source, $filters, $mode, $chunkingOverrides);

        RunProgress::transition($run, RunStatus::Chunking);

        $options = ChunkingOptions::fromArray((array) $run->chunking_params);
        $documentIds = [];
        $done = 0;

        foreach ($source->documents($filters) as $draft) {
            /** @var DocumentDraft $draft */
            $item = IngestionRunItem::query()
                ->where('run_id', $run->id)
                ->where('external_id', $draft->externalId)
                ->first();

            try {
                $result = $this->ingestor->ingest(
                    $source,
                    $draft,
                    $options,
                    $mode,
                    $run->embedding_model,
                    $run->embedding_dimensions,
                );

                $documentIds[] = $result->document->id;

                $item?->forceFill([
                    'document_id' => $result->document->id,
                    'status' => $result->skipped ? RunItemStatus::Skipped : RunItemStatus::Chunked,
                    'chunks_created' => $result->chunksCreated,
                    'chunks_reused' => $result->chunksReused,
                    'chunks_deleted' => $result->chunksDeleted,
                    'tokens' => $result->tokens,
                    'duration_ms' => $result->durationMs,
                ])->save();

                if ($result->skipped) {
                    RunProgress::documentSkipped($run->id);
                } else {
                    RunProgress::documentDone(
                        $run->id,
                        $result->chunksCreated,
                        $result->chunksReused,
                        $result->chunksDeleted,
                    );
                }
            } catch (Throwable $exception) {
                $item?->forceFill([
                    'status' => RunItemStatus::Failed,
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();

                RunProgress::documentFailed($run->id);
            }

            $done++;
            if ($onProgress !== null) {
                $onProgress('chunking', $done, $run->documents_total);
            }
        }

        $this->embedPending($run, $documentIds, $onProgress);

        $run->refresh();

        RunProgress::transition(
            $run,
            $run->documents_failed > 0 && $run->documents_done === 0
                ? RunStatus::Failed
                : RunStatus::Completed,
        );

        return $run->refresh();
    }

    /**
     * @param  array<int, int>  $documentIds
     * @param  Closure(string, int, int): void|null  $onProgress
     */
    private function embedPending(IngestionRun $run, array $documentIds, ?Closure $onProgress): void
    {
        if ($documentIds === []) {
            return;
        }

        $chunkIds = Chunk::query()
            ->whereIn('document_id', $documentIds)
            ->stale($run->embedding_model, $run->embedding_dimensions)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $total = count($chunkIds);

        $run->forceFill(['chunks_total' => $total, 'status' => RunStatus::Embedding])->save();

        if ($total === 0) {
            return;
        }

        $perCall = max(1, (int) config('filament-ai.queue.chunks_per_job', 96));
        $done = 0;

        foreach (array_chunk($chunkIds, $perCall) as $group) {
            $result = $this->embedder->embed(array_map(intval(...), $group));

            RunProgress::chunksEmbedded(
                $run->id,
                $result['embedded'],
                $result['tokens'],
                $result['cost_micros'],
            );

            $done += count($group);
            if ($onProgress !== null) {
                $onProgress('embedding', $done, $total);
            }
        }
    }
}
