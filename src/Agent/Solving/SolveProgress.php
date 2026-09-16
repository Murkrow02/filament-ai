<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Solving;

use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Events\SolveRunFinished;
use Murkrow\FilamentAi\Models\SolveRun;

/**
 * Counters for a run whose attempts are running at the same time.
 *
 * Atomic `incrementEach`, never read-modify-write: a wave is several workers
 * adding their tokens to the same row, and the budget that stops the run is
 * only as trustworthy as those numbers. Same reason `RunProgress` exists for
 * ingestion.
 */
final class SolveProgress
{
    /**
     * @param  array<string, int>  $counters
     */
    public static function increment(SolveRun|int $run, array $counters): void
    {
        $id = $run instanceof SolveRun ? $run->id : $run;

        $counters = array_filter($counters, static fn (int $value): bool => $value !== 0);

        if ($counters === []) {
            return;
        }

        SolveRun::query()->whereKey($id)->incrementEach($counters, ['updated_at' => now()]);
    }

    public static function attemptFinished(SolveRun|int $run, int $tokens, int $costMicros, bool $failed = false): void
    {
        self::increment($run, [
            'attempts_total' => 1,
            'attempts_failed' => $failed ? 1 : 0,
            'tokens_used' => $tokens,
            'cost_micros' => $costMicros,
        ]);
    }

    public static function judged(SolveRun|int $run, int $tokens, int $costMicros): void
    {
        self::increment($run, ['tokens_used' => $tokens, 'cost_micros' => $costMicros]);
    }

    public static function finish(SolveRun $run, SolveStatus $status, ?string $message = null): void
    {
        $run->forceFill([
            'status' => $status,
            'message' => $message,
            'finished_at' => now(),
        ])->save();

        SolveRunFinished::dispatch($run->refresh());
    }
}
