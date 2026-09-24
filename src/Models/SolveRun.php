<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Bus;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Models\Concerns\UsesAiConnection;

/**
 * One iterative search for an answer: a goal, the criteria it is judged
 * against, and the waves of attempts it took.
 *
 * @property int $id
 * @property string $uuid
 * @property SolveStatus $status
 * @property string $goal
 * @property string|null $criteria
 * @property array<string, mixed>|null $context
 * @property array{panel: ?string, tenant: ?string, user: int|string|null}|null $scope
 * @property array<string, mixed>|null $budgets
 * @property int $wave
 * @property int $waves_total
 * @property int $attempts_per_wave
 * @property int $attempts_total
 * @property int $attempts_failed
 * @property int|null $best_attempt_id
 * @property int $best_score
 * @property string|null $message
 * @property int $tokens_used
 * @property int $cost_micros
 * @property string|null $batch_id
 * @property string|null $assistant
 * @property string|null $strategy
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class SolveRun extends Model
{
    use UsesAiConnection;

    protected $guarded = [];

    protected function aiTableKey(): string
    {
        return 'solve_runs';
    }

    protected function casts(): array
    {
        return [
            'status' => SolveStatus::class,
            'context' => 'array',
            'scope' => 'array',
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
            Bus::findBatch($this->batch_id)?->cancel();
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
