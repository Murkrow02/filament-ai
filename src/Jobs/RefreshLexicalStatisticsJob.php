<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Events\DocumentIngested;
use Murkrow\FilamentAi\Jobs\Concerns\InteractsWithRagQueue;
use Murkrow\FilamentAi\Support\FullText;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Recount the full-text word frequencies after documents were ingested.
 *
 * The tsvector itself is written by a trigger with each chunk; only the counts
 * of how many chunks hold each word lag behind. Unique and delayed, so a book
 * of three hundred pages, or a whole import, costs one recount after it
 * settles rather than one per document.
 */
class RefreshLexicalStatisticsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithRagQueue;
    use Queueable;

    public int $tries = 1;

    /** While one is waiting, further ingestions do not queue another. */
    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->configureRagQueue();
        $this->delay((int) config('filament-ai.retrieval.hybrid.statistics_delay', 60));
    }

    /**
     * The listener: queue a recount when a document's chunks changed.
     */
    public static function afterIngestion(DocumentIngested $event): void
    {
        if ($event->chunksCreated === 0 && $event->chunksDeleted === 0) {
            return;
        }

        if (! (bool) config('filament-ai.retrieval.hybrid.refresh_statistics', true)) {
            return;
        }

        /** @var Connection $connection */
        $connection = DB::connection(Tables::connection());

        if ($connection->getDriverName() !== 'pgsql' || ! FullText::installed($connection)) {
            return;
        }

        static::dispatch();
    }

    public function handle(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection(Tables::connection());

        FullText::refreshStatistics($connection);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['filament-ai', 'ai:lexemes'];
    }
}
