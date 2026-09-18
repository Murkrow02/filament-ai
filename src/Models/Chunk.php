<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Murkrow\FilamentAi\Database\Factories\ChunkFactory;
use Murkrow\FilamentAi\Models\Concerns\UsesAiConnection;
use Murkrow\FilamentAi\Support\Text;
use Pgvector\Laravel\Vector;

/**
 * One embedded window of text.
 *
 * position_start / position_end are real indexed columns rather than JSON
 * metadata because page-range filtering is a first-class retrieval feature.
 * They also make the SQL overlap test trivial:
 *
 *     position_start <= :to AND position_end >= :from
 *
 * @property int $id
 * @property int $document_id
 * @property string $source_key
 * @property int $ordinal
 * @property int $position_start
 * @property int $position_end
 * @property int $char_start
 * @property int $char_end
 * @property string $content
 * @property string $content_hash
 * @property int $token_count
 * @property string|null $embedding_model
 * @property int|null $embedding_dimensions
 * @property \Illuminate\Support\Carbon|null $embedded_at
 * @property array<string, mixed>|null $metadata
 * @property Vector|null $embedding
 */
class Chunk extends Model
{
    /** @use HasFactory<ChunkFactory> */
    use HasFactory;

    use UsesAiConnection;

    protected $guarded = [];

    protected function aiTableKey(): string
    {
        return 'chunks';
    }

    /**
     * The embedding cast is driver-dependent: only pgvector stores a `vector`
     * column, and casting a JSON payload through Vector would fail. Resolving
     * it here rather than in a static property keeps the model usable under any
     * driver, including the in-memory one the test suite runs on.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $casts = [
            'ordinal' => 'integer',
            'position_start' => 'integer',
            'position_end' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'token_count' => 'integer',
            'embedding_dimensions' => 'integer',
            'embedded_at' => 'datetime',
            'metadata' => 'array',
        ];

        if (config('filament-ai.vector.driver', 'pgvector') === 'pgvector' && class_exists(Vector::class)) {
            $casts['embedding'] = Vector::class;
        }

        return $casts;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isEmbedded(): bool
    {
        return $this->embedded_at !== null;
    }

    public function snippet(int $chars = 240): string
    {
        return Text::snippet($this->content, $chars);
    }

    /**
     * Chunks whose vector is missing or was produced by a different model.
     *
     * @param  Builder<Chunk>  $query
     * @return Builder<Chunk>
     */
    public function scopeStale(Builder $query, string $model, int $dimensions): Builder
    {
        return $query->where(function (Builder $q) use ($model, $dimensions): void {
            $q->whereNull('embedded_at')
                ->orWhere('embedding_model', '!=', $model)
                ->orWhere('embedding_dimensions', '!=', $dimensions);
        });
    }

    /**
     * @param  Builder<Chunk>  $query
     * @return Builder<Chunk>
     */
    public function scopePendingEmbedding(Builder $query): Builder
    {
        return $query->whereNull('embedded_at');
    }

    /**
     * Overlap test against a position (page) range.
     *
     * @param  Builder<Chunk>  $query
     * @return Builder<Chunk>
     */
    public function scopeOverlappingPositions(Builder $query, ?int $from, ?int $to): Builder
    {
        if ($from !== null) {
            $query->where('position_end', '>=', $from);
        }

        if ($to !== null) {
            $query->where('position_start', '<=', $to);
        }

        return $query;
    }

    protected static function newFactory(): ChunkFactory
    {
        return ChunkFactory::new();
    }
}
