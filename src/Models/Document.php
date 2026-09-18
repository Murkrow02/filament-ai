<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Murkrow\FilamentAi\Database\Factories\DocumentFactory;
use Murkrow\FilamentAi\Enums\DocumentStatus;
use Murkrow\FilamentAi\Models\Concerns\UsesAiConnection;

/**
 * A host-application record mirrored into the knowledge base.
 *
 * `external_id` is a string on purpose: it accommodates int, uuid and ulid
 * primary keys without the package caring which one the host uses.
 *
 * @property int $id
 * @property string $source_key
 * @property string $external_id
 * @property string|null $title
 * @property array<string, mixed>|null $metadata
 * @property int $segment_count
 * @property int $chunk_count
 * @property int $embedded_chunk_count
 * @property string|null $content_checksum
 * @property string|null $params_checksum
 * @property int $token_count
 * @property DocumentStatus $status
 * @property \Illuminate\Support\Carbon|null $last_ingested_at
 * @property string|null $last_error
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use UsesAiConnection;

    protected $guarded = [];

    protected function aiTableKey(): string
    {
        return 'documents';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'segment_count' => 'integer',
            'chunk_count' => 'integer',
            'embedded_chunk_count' => 'integer',
            'token_count' => 'integer',
            'status' => DocumentStatus::class,
            'last_ingested_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Chunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function coverage(): float
    {
        return $this->chunk_count > 0
            ? $this->embedded_chunk_count / $this->chunk_count
            : 0.0;
    }

    public function isFullyEmbedded(): bool
    {
        return $this->chunk_count > 0 && $this->embedded_chunk_count >= $this->chunk_count;
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeForSource(Builder $query, string|array $sourceKey): Builder
    {
        return $query->whereIn('source_key', (array) $sourceKey);
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereColumn('embedded_chunk_count', '<', 'chunk_count');
    }

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }
}
