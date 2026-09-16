<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Models\IngestionRun;

/**
 * Progress accounting for an ingestion run.
 *
 * Every counter moves through an atomic SQL increment rather than a
 * read-modify-write on a model: several queue workers report progress on the
 * same run concurrently, and Eloquent's optimistic path would quietly lose
 * updates under that.
 */
final class RunProgress
{
    /**
     * @param  array<string, int>  $counters
     */
    public static function increment(IngestionRun|int $run, array $counters): void
    {
        $id = $run instanceof IngestionRun ? $run->id : $run;

        $counters = array_filter($counters, static fn (int $v): bool => $v !== 0);

        if ($counters === []) {
            return;
        }

        IngestionRun::query()->whereKey($id)->incrementEach($counters, ['updated_at' => now()]);
    }

    public static function documentDone(
        IngestionRun|int $run,
        int $created,
        int $reused,
        int $deleted,
    ): void {
        self::increment($run, [
            'documents_done' => 1,
            'chunks_created' => $created,
            'chunks_reused' => $reused,
            'chunks_deleted' => $deleted,
        ]);
    }

    public static function documentSkipped(IngestionRun|int $run): void
    {
        self::increment($run, ['documents_skipped' => 1]);
    }

    public static function documentFailed(IngestionRun|int $run): void
    {
        self::increment($run, ['documents_failed' => 1]);
    }

    public static function chunksEmbedded(
        IngestionRun|int $run,
        int $chunks,
        int $tokens,
        int $costMicros,
        int $apiCalls = 1,
    ): void {
        self::increment($run, [
            'chunks_embedded' => $chunks,
            'tokens_used' => $tokens,
            'cost_micros' => $costMicros,
            'api_calls' => $apiCalls,
        ]);
    }

    public static function chunksFailed(IngestionRun|int $run, int $chunks): void
    {
        self::increment($run, ['chunks_failed' => $chunks]);
    }

    public static function transition(IngestionRun $run, RunStatus $status, ?string $error = null): void
    {
        $attributes = ['status' => $status->value];

        if ($status === RunStatus::Chunking && $run->started_at === null) {
            $attributes['started_at'] = now();
        }

        if ($status->isTerminal()) {
            $attributes['finished_at'] = now();
        }

        if ($error !== null) {
            $attributes['last_error'] = mb_substr($error, 0, 2000);
        }

        IngestionRun::query()->whereKey($run->id)->update($attributes);

        $run->refresh();
    }
}
