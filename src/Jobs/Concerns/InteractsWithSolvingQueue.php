<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Jobs\Concerns;

/**
 * Solving gets its own queue settings, defaulting to the ingestion ones.
 *
 * A wave of attempts is slow and expensive, and an application that wants it
 * on a separate worker -- so a long search cannot starve indexing -- says so
 * once in `filament-ai.agent.solving.queue`.
 */
trait InteractsWithSolvingQueue
{
    public function configureSolvingQueue(): void
    {
        $this->onConnection(self::solvingConnection());
        $this->onQueue(self::solvingQueue());
    }

    public static function solvingConnection(): ?string
    {
        $connection = config('filament-ai.agent.solving.queue.connection', config('filament-ai.queue.connection'));

        return blank($connection) ? null : (string) $connection;
    }

    public static function solvingQueue(): string
    {
        return (string) config('filament-ai.agent.solving.queue.queue', config('filament-ai.queue.queue', 'rag'));
    }
}
