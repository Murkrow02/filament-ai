<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Murkrow\FilamentAi\Embeddings\EmbeddingRateLimiter;
use Murkrow\FilamentAi\Ingestion\ChunkEmbedder;
use Murkrow\FilamentAi\Ingestion\RunProgress;
use Murkrow\FilamentAi\Jobs\Concerns\InteractsWithRagQueue;
use Throwable;

/**
 * Embedding phase: one API call per job.
 *
 * The job carries chunk ids rather than text so its payload stays small and,
 * more importantly, so a retry re-reads current state. ChunkEmbedder skips rows
 * that already have a vector, which makes a retry after a partial failure free
 * instead of a second charge for the same tokens.
 */
class EmbedChunkGroupJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithRagQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    /**
     * @param  array<int, int>  $chunkIds
     */
    public function __construct(
        public readonly array $chunkIds,
        public readonly ?int $runId = null,
    ) {
        $this->tries = (int) config('rag.queue.tries', 5);
        $this->timeout = (int) config('rag.queue.timeout', 300);

        $this->configureRagQueue();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited(EmbeddingRateLimiter::NAME)];
    }

    public function handle(ChunkEmbedder $embedder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $result = $embedder->embed($this->chunkIds);
        } catch (Throwable $exception) {
            if ($this->runId !== null && $this->attempts() >= $this->tries) {
                RunProgress::chunksFailed($this->runId, count($this->chunkIds));
            }

            // A provider-side rate limit is not a failure; wait it out instead
            // of spending an attempt.
            $retryAfter = $this->retryAfterFor($exception);

            if ($retryAfter !== null) {
                $this->release($retryAfter);

                return;
            }

            throw $exception;
        }

        if ($this->runId !== null) {
            RunProgress::chunksEmbedded(
                $this->runId,
                $result['embedded'],
                $result['tokens'],
                $result['cost_micros'],
            );
        }
    }

    private function retryAfterFor(Throwable $exception): ?int
    {
        $message = strtolower($exception->getMessage());

        $isRateLimit = str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests')
            || str_contains($message, '429');

        if (! $isRateLimit) {
            return null;
        }

        $backoff = $this->backoff();
        $index = max(0, min($this->attempts() - 1, count($backoff) - 1));

        return $backoff[$index] ?? 60;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return array_filter(['rag', 'rag:embed', $this->runId === null ? null : 'rag:run:'.$this->runId]);
    }
}
