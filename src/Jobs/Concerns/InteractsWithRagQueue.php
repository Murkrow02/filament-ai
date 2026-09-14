<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Jobs\Concerns;

/**
 * Pulls queue behaviour from config instead of hardcoding it, so a host can
 * isolate ingestion on its own connection and queue without editing the
 * package -- which matters because a bulk ingest will otherwise starve
 * latency-sensitive jobs sharing the default queue.
 */
trait InteractsWithRagQueue
{
    public function configureRagQueue(): void
    {
        $this->onConnection((string) config('rag.queue.connection'));
        $this->onQueue((string) config('rag.queue.queue', 'rag'));
    }

    public function retryUntil(): ?\DateTimeInterface
    {
        return null;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        /** @var array<int, int> $backoff */
        $backoff = (array) config('rag.queue.backoff', [10, 30, 60, 120, 300]);

        return $backoff;
    }
}
