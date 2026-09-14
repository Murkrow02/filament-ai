<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Jobs\EmbedChunkGroupJob;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Models\IngestionRunItem;

/**
 * Second phase of a run: collect every chunk still missing a vector and fan
 * them out, `chunks_per_job` at a time.
 *
 * Chunk ids are collected after chunking rather than during it, so the batch
 * size reflects what actually needs embedding -- on an incremental run over an
 * unchanged corpus that is usually zero, and the run closes immediately.
 */
final class EmbeddingDispatcher
{
    public function dispatchFor(IngestionRun $run): ?Batch
    {
        $documentIds = IngestionRunItem::query()
            ->where('run_id', $run->id)
            ->whereNotNull('document_id')
            ->pluck('document_id')
            ->all();

        if ($documentIds === []) {
            $this->close($run);

            return null;
        }

        $chunkIds = Chunk::query()
            ->whereIn('document_id', $documentIds)
            ->stale($run->embedding_model, $run->embedding_dimensions)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($chunkIds === []) {
            $this->close($run);

            return null;
        }

        $perJob = max(1, (int) config('rag.queue.chunks_per_job', 96));

        $jobs = array_map(
            static fn (array $group): EmbedChunkGroupJob => new EmbedChunkGroupJob(
                array_map(intval(...), $group),
                $run->id,
            ),
            array_chunk($chunkIds, $perJob),
        );

        $runId = $run->id;

        $batch = Bus::batch($jobs)
            ->name("rag:embed:{$run->uuid}")
            ->onConnection((string) config('rag.queue.connection'))
            ->onQueue((string) config('rag.queue.queue', 'rag'))
            ->allowFailures((bool) config('rag.queue.allow_failures', true))
            ->finally(static function () use ($runId): void {
                $run = IngestionRun::query()->find($runId);

                if ($run === null || $run->status === RunStatus::Cancelled) {
                    return;
                }

                RunProgress::transition(
                    $run,
                    $run->chunks_failed > 0 && $run->chunks_embedded === 0
                        ? RunStatus::Failed
                        : RunStatus::Completed,
                );
            })
            ->dispatch();

        $run->forceFill([
            'embed_batch_id' => $batch->id,
            'chunks_total' => count($chunkIds),
            'status' => RunStatus::Embedding,
        ])->save();

        return $batch;
    }

    private function close(IngestionRun $run): void
    {
        $run->forceFill(['chunks_total' => 0])->save();

        RunProgress::transition($run, RunStatus::Completed);
    }
}
