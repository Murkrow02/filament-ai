<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Murkrow\FilamentAi\Enums\QueryChannel;
use Murkrow\FilamentAi\Models\Concerns\UsesAiConnection;

/**
 * Audit log of one retrieval + answer cycle, with its citations.
 *
 * Doubles as the evaluation dataset: question, what was retrieved, what was
 * answered, and whether a human gave it a thumbs up or down.
 *
 * @property int $id
 * @property string $uuid
 * @property array<int, string>|null $source_keys
 * @property string $question
 * @property string $question_hash
 * @property string|null $embedding_model
 * @property string|null $llm_model
 * @property array<string, mixed>|null $filters
 * @property int $top_k
 * @property int $retrieved_count
 * @property float|null $top_score
 * @property float|null $min_score
 * @property string|null $answer
 * @property bool $refused
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $embedding_tokens
 * @property int $cost_micros
 * @property int|null $latency_ms
 * @property int|null $retrieval_ms
 * @property QueryChannel $channel
 * @property int|string|null $user_id
 * @property int|null $conversation_id
 * @property int $turn
 * @property int|null $feedback
 */
class QueryLog extends Model
{
    use UsesAiConnection;

    protected $guarded = [];

    protected function aiTableKey(): string
    {
        return 'queries';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_keys' => 'array',
            'filters' => 'array',
            'top_k' => 'integer',
            'retrieved_count' => 'integer',
            'top_score' => 'float',
            'min_score' => 'float',
            'refused' => 'boolean',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'embedding_tokens' => 'integer',
            'cost_micros' => 'integer',
            'latency_ms' => 'integer',
            'retrieval_ms' => 'integer',
            'channel' => QueryChannel::class,
            'feedback' => 'integer',
            'conversation_id' => 'integer',
            'turn' => 'integer',
        ];
    }

    /**
     * @return HasMany<QueryCitation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(QueryCitation::class, 'query_id');
    }

    /**
     * The chat conversation this turn belongs to, when it came from the chat
     * page. Null for the CLI, MCP, the API and the Filament playground.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function costUsd(): float
    {
        return $this->cost_micros / 1_000_000;
    }
}
