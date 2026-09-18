<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Murkrow\FilamentAi\Enums\RunItemStatus;
use Murkrow\FilamentAi\Models\Concerns\UsesAiConnection;

/**
 * Per-document outcome inside an ingestion run. Kept as its own table so a
 * failed run tells you exactly which documents failed and why.
 *
 * @property int $id
 * @property int $run_id
 * @property int|null $document_id
 * @property string $external_id
 * @property RunItemStatus $status
 * @property int $chunks_created
 * @property int $chunks_reused
 * @property int $chunks_deleted
 * @property int $tokens
 * @property int|null $duration_ms
 * @property string|null $error
 */
class IngestionRunItem extends Model
{
    use UsesAiConnection;

    protected $guarded = [];

    protected function aiTableKey(): string
    {
        return 'run_items';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunItemStatus::class,
            'chunks_created' => 'integer',
            'chunks_reused' => 'integer',
            'chunks_deleted' => 'integer',
            'tokens' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IngestionRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(IngestionRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
