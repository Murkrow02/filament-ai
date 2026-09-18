<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Murkrow\FilamentAi\Models\Concerns\UsesAiConnection;

/**
 * A chunk that was placed in the prompt for a given query. `used` records
 * whether the model actually referenced it in the answer, which is the signal
 * for tuning top_k.
 *
 * @property int $id
 * @property int $query_id
 * @property int|null $chunk_id
 * @property int|null $document_id
 * @property int $marker
 * @property float $score
 * @property int $rank
 * @property int $position_start
 * @property int $position_end
 * @property bool $used
 * @property string|null $snippet
 */
class QueryCitation extends Model
{
    use UsesAiConnection;

    public $timestamps = false;

    protected $guarded = [];

    protected function aiTableKey(): string
    {
        return 'citations';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marker' => 'integer',
            'score' => 'float',
            'rank' => 'integer',
            'position_start' => 'integer',
            'position_end' => 'integer',
            'used' => 'boolean',
        ];
    }

    /**
     * Named queryLog() rather than query(): Eloquent already defines a static
     * query() on every model, and redeclaring it as a relation is a fatal error.
     *
     * @return BelongsTo<QueryLog, $this>
     */
    public function queryLog(): BelongsTo
    {
        return $this->belongsTo(QueryLog::class, 'query_id');
    }

    /**
     * @return BelongsTo<Chunk, $this>
     */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(Chunk::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
