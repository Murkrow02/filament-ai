<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Enums\RunItemStatus;
use Murkrow\FilamentAi\Exceptions\IngestionException;
use Murkrow\FilamentAi\Ingestion\DocumentIngestor;
use Murkrow\FilamentAi\Ingestion\RunProgress;
use Murkrow\FilamentAi\Jobs\Concerns\InteractsWithRagQueue;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Models\IngestionRunItem;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Throwable;

/**
 * Chunking phase, one document per job.
 *
 * One document per job rather than a batch of them: documents vary enormously
 * in size, and per-document granularity means a failure isolates to a single
 * record and a retry re-does only that record's work.
 */
class PrepareDocumentJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithRagQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly int $runId,
        public readonly string $externalId,
    ) {
        $this->tries = (int) config('filament-ai.queue.tries', 5);
        $this->timeout = (int) config('filament-ai.queue.timeout', 300);

        $this->configureRagQueue();
    }

    public function handle(SourceRegistry $sources, DocumentIngestor $ingestor): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = IngestionRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        $item = IngestionRunItem::query()
            ->where('run_id', $run->id)
            ->where('external_id', $this->externalId)
            ->first();

        try {
            $source = $sources->get($run->source_key);
            $draft = $source->findDocument($this->externalId);

            if ($draft === null) {
                throw IngestionException::documentNotFound($run->source_key, $this->externalId);
            }

            $result = $ingestor->ingest(
                $source,
                $draft,
                ChunkingOptions::fromArray((array) $run->chunking_params),
                $run->mode,
                $run->embedding_model,
                $run->embedding_dimensions,
            );

            $item?->forceFill([
                'document_id' => $result->document->id,
                'status' => $result->skipped ? RunItemStatus::Skipped : RunItemStatus::Chunked,
                'chunks_created' => $result->chunksCreated,
                'chunks_reused' => $result->chunksReused,
                'chunks_deleted' => $result->chunksDeleted,
                'tokens' => $result->tokens,
                'duration_ms' => $result->durationMs,
                'error' => null,
            ])->save();

            if ($result->skipped) {
                RunProgress::documentSkipped($run->id);

                return;
            }

            RunProgress::documentDone(
                $run->id,
                $result->chunksCreated,
                $result->chunksReused,
                $result->chunksDeleted,
            );
        } catch (Throwable $exception) {
            $item?->forceFill([
                'status' => RunItemStatus::Failed,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            RunProgress::documentFailed($run->id);

            throw $exception;
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['filament-ai', 'ai:chunk', 'ai:run:'.$this->runId];
    }
}
