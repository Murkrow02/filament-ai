<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Bus;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Models\Concerns\UsesRagConnection;

/**
 * One ingestion job, from planning to completion.
 *
 * The run freezes the parameters it was launched with (chunking, model,
 * dimensions, driver) so that a config change mid-flight cannot produce a
 * corpus chunked two different ways.
 *
 * @property int $id
 * @property string $uuid
 * @property string $source_key
 * @property RunStatus $status
 * @property IngestionMode $mode
 * @property array<string, mixed>|null $filters
 * @property array<string, mixed> $chunking_params
 * @property string $embedding_model
 * @property int $embedding_dimensions
 * @property string $vector_driver
 * @property string|null $chunk_batch_id
 * @property string|null $embed_batch_id
 * @property int $documents_total
 * @property int $documents_done
 * @property int $documents_skipped
 * @property int $documents_failed
 * @property int $chunks_created
 * @property int $chunks_reused
 * @property int $chunks_deleted
 * @property int $chunks_total
 * @property int $chunks_embedded
 * @property int $chunks_failed
 * @property int $tokens_used
 * @property int $cost_micros
 * @property int $api_calls
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property string|null $last_error
 * @property int|string|null $created_by
 */
class IngestionRun extends Model
{
    use UsesRagConnection;

    protected $guarded = [];

    protected function ragTableKey(): string
    {
        return 'runs';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'mode' => IngestionMode::class,
            'filters' => 'array',
            'chunking_params' => 'array',
            'embedding_dimensions' => 'integer',
            'documents_total' => 'integer',
            'documents_done' => 'integer',
            'documents_skipped' => 'integer',
            'documents_failed' => 'integer',
            'chunks_created' => 'integer',
            'chunks_reused' => 'integer',
            'chunks_deleted' => 'integer',
            'chunks_total' => 'integer',
            'chunks_embedded' => 'integer',
            'chunks_failed' => 'integer',
            'tokens_used' => 'integer',
            'cost_micros' => 'integer',
            'api_calls' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<IngestionRunItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(IngestionRunItem::class, 'run_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Overall progress in 0..1, weighting the chunking and embedding phases by
     * how much work each actually represents.
     */
    public function progress(): float
    {
        return match ($this->status) {
            RunStatus::Completed => 1.0,
            RunStatus::Queued => 0.0,
            RunStatus::Chunking => $this->documents_total > 0
                ? 0.3 * (($this->documents_done + $this->documents_skipped) / $this->documents_total)
                : 0.0,
            RunStatus::Embedding => 0.3 + ($this->chunks_total > 0
                ? 0.7 * (($this->chunks_embedded + $this->chunks_failed) / $this->chunks_total)
                : 0.0),
            default => $this->chunks_total > 0
                ? ($this->chunks_embedded + $this->chunks_failed) / $this->chunks_total
                : 0.0,
        };
    }

    public function progressPercent(): int
    {
        return (int) round($this->progress() * 100);
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

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), absolute: true);
    }

    /**
     * The batch currently doing the work, if any.
     */
    public function currentBatch(): ?Batch
    {
        $id = $this->embed_batch_id ?? $this->chunk_batch_id;

        return $id === null ? null : Bus::findBatch($id);
    }

    public function failedJobs(): int
    {
        return $this->currentBatch()?->failedJobs ?? 0;
    }

    public function cancel(): void
    {
        foreach ([$this->embed_batch_id, $this->chunk_batch_id] as $id) {
            if ($id !== null) {
                Bus::findBatch($id)?->cancel();
            }
        }

        $this->forceFill([
            'status' => RunStatus::Cancelled,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  Builder<IngestionRun>  $query
     * @return Builder<IngestionRun>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [
            RunStatus::Queued->value,
            RunStatus::Chunking->value,
            RunStatus::Embedding->value,
        ]);
    }
}
