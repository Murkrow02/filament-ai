<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Events\IngestionRunFinished;
use Murkrow\FilamentAi\Ingestion\EmbeddingDispatcher;
use Murkrow\FilamentAi\Jobs\Concerns\InteractsWithRagQueue;
use Murkrow\FilamentAi\Models\IngestionRun;

/**
 * Bridges the two phases of a run.
 *
 * Runs as the chunking batch's `finally` callback, then launches the embedding
 * batch. Deliberately a separate job rather than inline callback work: it can
 * be slow (a `pluck` over a large corpus) and must not block the queue worker
 * that happened to finish the last chunking job.
 */
class FinalizeIngestionRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithRagQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
        $this->configureRagQueue();
    }

    public function handle(EmbeddingDispatcher $dispatcher): void
    {
        $run = IngestionRun::query()->find($this->runId);

        if ($run === null || $run->status === RunStatus::Cancelled) {
            return;
        }

        $dispatcher->dispatchFor($run);

        $run->refresh();

        if ($run->status->isTerminal()) {
            IngestionRunFinished::dispatch($run);
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['filament-ai', 'ai:finalize', 'ai:run:'.$this->runId];
    }
}
