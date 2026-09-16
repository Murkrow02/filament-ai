<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Models\Concerns\UsesRagConnection;

/**
 * One iterative search for an answer: a goal, the criteria it is judged
 * against, and the waves of attempts it took.
 */
class SolveRun extends Model
{
    use UsesRagConnection;

    protected $guarded = [];

    protected function ragTableKey(): string
    {
        return 'solve_runs';
    }

    protected function casts(): array
    {
        return [
            'status' => SolveStatus::class,
            'context' => 'array',
            'budgets' => 'array',
            'wave' => 'integer',
            'waves_total' => 'integer',
            'attempts_per_wave' => 'integer',
            'attempts_total' => 'integer',
            'attempts_failed' => 'integer',
            'best_score' => 'integer',
            'tokens_used' => 'integer',
            'cost_micros' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SolveAttempt::class, 'run_id');
    }

    public function best(): BelongsTo
    {
        return $this->belongsTo(SolveAttempt::class, 'best_attempt_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function costUsd(): float
    {
        return $this->cost_micros / 1_000_000;
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        // Carbon 3 answers in float seconds; the cast is the difference
        // between a duration and a TypeError inside a queued job.
        return (int) ($this->finished_at ?? now())->diffInSeconds($this->started_at, absolute: true);
    }

    /**
     * Waves are the only honest progress here: an attempt either has an answer
     * or it does not, and how close the answer is is what the verifier says.
     */
    public function progressPercent(): int
    {
        if ($this->status === SolveStatus::Solved) {
            return 100;
        }

        if ($this->waves_total < 1) {
            return 0;
        }

        return (int) round(100 * min(1.0, $this->wave / $this->waves_total));
    }

    /**
     * Stop after the attempts already in flight.
     *
     * The batch is cancelled so no queued attempt starts, and the run is
     * closed as exhausted rather than failed: what it found is still valid,
     * somebody just decided it was enough.
     */
    public function cancel(): void
    {
        if ($this->status->isTerminal()) {
            return;
        }

        if ($this->batch_id !== null) {
            \Illuminate\Support\Facades\Bus::findBatch($this->batch_id)?->cancel();
        }

        $this->forceFill([
            'status' => SolveStatus::Cancelled,
            'finished_at' => now(),
        ])->save();
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [SolveStatus::Queued->value, SolveStatus::Running->value]);
    }
}
