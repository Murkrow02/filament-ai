<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Throwable;

/**
 * Reads a user's assistant conversations back out of laravel/ai's tables, in
 * the shape the chat page renders.
 *
 * laravel/ai's store is the only record of a conversation: the page keeps no
 * copy, so a reload, a second tab or a turn paused for approval all show the
 * same thing. Every read is scoped to the participant, so one user can never
 * open, list or resume another user's conversation by guessing its id.
 */
final class ConversationTranscript
{
    /**
     * The tables come from laravel/ai's publishable migrations, which a host
     * may not have run yet.
     */
    public function available(): bool
    {
        try {
            $schema = Schema::connection((new Conversation)->getConnectionName());

            return $schema->hasTable((new Conversation)->getTable())
                && $schema->hasTable((new ConversationMessage)->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Rename a conversation the user owns. Returns the stored title, or null
     * when the conversation is not theirs.
     */
    public function rename(string $conversationId, object $user, string $title): ?string
    {
        $conversation = $this->forUser($user)->whereKey($conversationId)->first();

        if ($conversation === null) {
            return null;
        }

        $title = trim($title);

        $conversation->forceFill(['title' => $title === '' ? null : mb_substr($title, 0, 200)])->save();

        return $conversation->title;
    }

    /**
     * Delete a conversation the user owns, messages included.
     */
    public function delete(string $conversationId, object $user): bool
    {
        $conversation = $this->forUser($user)->whereKey($conversationId)->first();

        if ($conversation === null) {
            return false;
        }

        ConversationMessage::query()->where('conversation_id', $conversation->getKey())->delete();

        return (bool) $conversation->delete();
    }

    public function title(string $conversationId): ?string
    {
        return Conversation::query()->whereKey($conversationId)->value('title');
    }

    public function owns(string $conversationId, object $user): bool
    {
        return $conversationId !== '' && $this->forUser($user)->whereKey($conversationId)->exists();
    }

    /**
     * @return list<array{id: string, title: string, updated_at: ?string}>
     */
    public function recent(object $user, int $limit): array
    {
        return $this->forUser($user)
            ->latest('updated_at')
            ->limit(max(1, $limit))
            ->get(['id', 'title', 'updated_at'])
            ->map(static fn (Conversation $conversation): array => [
                'id' => (string) $conversation->id,
                'title' => (string) $conversation->title,
                // Sent as a timestamp, not as "3 minutes ago": the page sorts
                // these against the knowledge threads and formats them itself.
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array{role: string, content: string, tools: list<array{id: string, name: string, status: string}>}>
     */
    public function messages(string $conversationId): array
    {
        $rows = $this->rows($conversationId);

        // A call resolved after an approval pause keeps its result on the
        // paused row, but gather across rows anyway: the store has moved
        // results between rows before.
        $results = [];

        foreach ($rows as $row) {
            foreach ((array) $row->tool_results as $result) {
                if (is_array($result) && isset($result['id'])) {
                    $results[(string) $result['id']] = $result;
                }
            }
        }

        $messages = [];

        foreach ($rows as $row) {
            if ($row->role === 'user') {
                $messages[] = ['role' => 'user', 'content' => (string) $row->content, 'tools' => []];

                continue;
            }

            $pending = $this->pendingOn($row);
            $tools = [];

            foreach ((array) $row->tool_calls as $call) {
                if (! is_array($call)) {
                    continue;
                }

                $id = (string) ($call['id'] ?? '');
                $result = $results[$id] ?? null;

                $tools[] = [
                    'id' => $id,
                    'name' => (string) ($call['name'] ?? ''),
                    'status' => match (true) {
                        array_key_exists($id, $pending), $result === null => 'pending',
                        (bool) ($result['denied'] ?? false) => 'denied',
                        (bool) ($result['failed'] ?? false) => 'failed',
                        default => 'done',
                    },
                ];
            }

            if (trim((string) $row->content) === '' && $tools === []) {
                continue;
            }

            $messages[] = ['role' => 'assistant', 'content' => (string) $row->content, 'tools' => $tools];
        }

        return $messages;
    }

    /**
     * Tool calls waiting for the user's decision, keyed by call id. Only the
     * latest assistant turn counts: an older pause the user walked away from
     * must not block the conversation forever.
     *
     * @return array<string, array{tool: string, reason: ?string, arguments: array<string, mixed>}>
     */
    public function pendingApprovals(string $conversationId): array
    {
        $latest = $this->rows($conversationId)->last(static fn (ConversationMessage $row): bool => $row->role === 'assistant');

        if ($latest === null) {
            return [];
        }

        $names = [];
        $arguments = [];

        foreach ((array) $latest->tool_calls as $call) {
            if (is_array($call) && isset($call['id'])) {
                $names[(string) $call['id']] = (string) ($call['name'] ?? '');
                $arguments[(string) $call['id']] = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
            }
        }

        $approvals = [];

        foreach ($this->pendingOn($latest) as $id => $reason) {
            $approvals[(string) $id] = [
                'tool' => $names[(string) $id] ?? '',
                'reason' => is_string($reason) && $reason !== '' ? $reason : null,
                'arguments' => $arguments[(string) $id] ?? [],
            ];
        }

        return $approvals;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ConversationMessage>
     */
    private function rows(string $conversationId): \Illuminate\Support\Collection
    {
        // Ids are UUIDv7, so ordering by id is ordering by creation even when
        // two rows share a created_at second.
        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingOn(ConversationMessage $row): array
    {
        $state = $row->approval_state;

        return is_array($state) && is_array($state['pending'] ?? null) ? $state['pending'] : [];
    }

    /**
     * @return Builder<Conversation>
     */
    private function forUser(object $user): Builder
    {
        return Conversation::query()
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user));
    }
}
