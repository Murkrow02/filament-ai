<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Data\AnswerResult;
use Murkrow\FilamentAi\Models\Concerns\UsesAiConnection;

/**
 * One chat thread on the standalone chat page.
 *
 * The conversation owns almost nothing: every turn is already a QueryLog row
 * with its question, answer, citations, tokens and cost, so this exists to
 * group them, order them and carry the handful of things a group needs --
 * a title, a pin, and the settings the answers were produced under.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $user_id
 * @property string|null $title
 * @property array<string, mixed>|null $settings
 * @property bool $pinned
 * @property int $turns
 * @property int $cost_micros
 * @property \Illuminate\Support\Carbon|null $last_message_at
 */
class Conversation extends Model
{
    use UsesAiConnection;

    protected $guarded = [];

    protected function aiTableKey(): string
    {
        return 'conversations';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'pinned' => 'boolean',
            'turns' => 'integer',
            'cost_micros' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            $conversation->uuid ??= (string) Str::uuid();
        });

        // Postgres cascades this through the foreign key, but SQLite cannot
        // hold one on a table that already existed when the column was added,
        // and orphaned query rows would keep their answers forever.
        static::deleting(function (self $conversation): void {
            QueryLog::query()->where('conversation_id', $conversation->getKey())->delete();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasMany<QueryLog, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(QueryLog::class, 'conversation_id')->orderBy('turn')->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, int|string|null $userId): Builder
    {
        return $userId === null
            ? $query->whereNull('user_id')
            : $query->where('user_id', (string) $userId);
    }

    /**
     * Pinned first, then most recently used. `last_message_at` rather than
     * `updated_at` so renaming an old thread does not jump it to the top.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query
            ->orderByDesc('pinned')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    public function costUsd(): float
    {
        return $this->cost_micros / 1_000_000;
    }

    /**
     * A title from the first question, cut on a word boundary.
     */
    public static function titleFrom(string $question): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);

        return $title === ''
            ? ''
            : Str::limit($title, 60, '…');
    }

    /**
     * The previous turns, in the shape AnswerOptions::$history expects.
     *
     * Refusals are excluded: replaying "I could not find this" into the prompt
     * teaches the model that refusing is the house style, and it is not useful
     * context for the next question either.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function recentHistory(int $turns): array
    {
        if ($turns < 1 || ! $this->exists) {
            return [];
        }

        $rows = $this->queries()
            ->where('refused', false)
            ->whereNotNull('answer')
            ->reorder()
            ->orderByDesc('turn')
            ->orderByDesc('id')
            ->limit($turns)
            ->get(['question', 'answer'])
            ->reverse();

        $history = [];

        foreach ($rows as $row) {
            $history[] = ['role' => 'user', 'content' => (string) $row->question];
            $history[] = ['role' => 'assistant', 'content' => (string) $row->answer];
        }

        return $history;
    }

    /**
     * Record that a turn completed.
     *
     * The counters are incremented in SQL rather than read-modify-written: two
     * tabs open on the same conversation is an ordinary thing for a chat UI,
     * and a lost update there would silently corrupt the running cost.
     */
    public function registerTurn(AnswerResult $result): void
    {
        $updates = [
            'last_message_at' => now(),
        ];

        if (($this->title ?? '') === '') {
            $updates['title'] = static::titleFrom($result->question);
        }

        $this->newQuery()->whereKey($this->getKey())->update($updates + [
            'turns' => $this->getConnection()->raw('turns + 1'),
            'cost_micros' => $this->getConnection()->raw('cost_micros + '.(int) $result->usage->costMicros),
            'updated_at' => now(),
        ]);

        $this->refresh();
    }
}
