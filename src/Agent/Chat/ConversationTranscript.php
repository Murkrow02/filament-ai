<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Enums\MessageStatus;
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
    /** The tools whose output carries citable passages. */
    private const KNOWLEDGE_TOOLS = ['search_knowledge'];

    /**
     * The tables come from laravel/ai's publishable migrations, which a host
     * may not have run yet.
     */
    public function available(): bool
    {
        try {
            $schema = Schema::connection((new Conversation)->getConnectionName());

            $messages = (new ConversationMessage)->getTable();

            // laravel/ai 1.0 replaced tool_calls/tool_results with one `steps`
            // column. A host that upgraded without running the backfill has
            // the table but not the column, and every read would fail.
            return $schema->hasTable((new Conversation)->getTable())
                && $schema->hasTable($messages)
                && $schema->hasColumns($messages, ['steps', 'status']);
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
     * @return list<array{role: string, content: string, tools: list<array{id: string, name: string, status: string}>, failed?: bool, passages?: list<array<string, mixed>>}>
     */
    public function messages(string $conversationId): array
    {
        $messages = [];

        foreach ($this->rows($conversationId) as $row) {
            if ($row->role === 'user') {
                $messages[] = ['role' => 'user', 'content' => (string) $row->content, 'tools' => []];

                continue;
            }

            // Since laravel/ai 1.0 a turn keeps every step, and each call its
            // own result, on one row: a resumed turn folds back into the row
            // it paused on.
            $tools = [];

            foreach ($row->tool_calls as $call) {
                if (! is_array($call)) {
                    continue;
                }

                $tools[] = [
                    'id' => (string) ($call['id'] ?? ''),
                    'name' => (string) ($call['name'] ?? ''),
                    'status' => match (true) {
                        ! PendingApproval::isAnswered($call) => 'pending',
                        (bool) ($call['denied'] ?? false) => 'denied',
                        (bool) ($call['failed'] ?? false) => 'failed',
                        default => 'done',
                    },
                ];
            }

            $failed = $row->status === MessageStatus::Failed;

            if (trim((string) $row->content) === '' && $tools === [] && ! $failed) {
                continue;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => (string) $row->content,
                'tools' => $tools,
                // A turn that died is stored with what it managed before the
                // error. The error itself stays in `meta` -- the page says
                // something went wrong, and only a debug reader sees more.
                'failed' => $failed,
                'error' => $failed ? $this->errorOf($row) : null,
                // A citation the reader cannot open is a decoration. The
                // passages are not stored as data anywhere, but the text the
                // knowledge tool returned is -- and this package wrote it, so
                // it can read it back.
                'passages' => $this->passagesFrom($row),
            ];
        }

        return $messages;
    }

    /**
     * The whole conversation for whoever audits it: every message with its
     * time and status, every tool call with its arguments and what it
     * returned, the tokens each answer used, and the error a failed turn
     * died with. Not for the chat itself, which shows people sentences.
     *
     * @return list<array{role: string, content: string, at: ?string, status: ?string, error: ?string, input_tokens: ?int, output_tokens: ?int, tools: list<array{name: string, label: string, arguments: array<string, mixed>, result: ?string, status: string}>}>
     */
    public function auditTrail(string $conversationId): array
    {
        $labels = app(ToolLabels::class);
        $trail = [];

        foreach ($this->rows($conversationId) as $row) {
            $tools = [];

            foreach ($row->role === 'user' ? [] : $row->tool_calls as $call) {
                if (! is_array($call)) {
                    continue;
                }

                $name = (string) ($call['name'] ?? '');
                $result = $call['result'] ?? null;

                $tools[] = [
                    'name' => $name,
                    'label' => $labels->label($name),
                    'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                    'result' => $result === null ? null : (is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_UNICODE)),
                    'status' => match (true) {
                        ! PendingApproval::isAnswered($call) => 'pending',
                        (bool) ($call['denied'] ?? false) => 'denied',
                        (bool) ($call['failed'] ?? false), is_string($result) && str_starts_with($result, 'Error:') => 'failed',
                        default => 'done',
                    },
                ];
            }

            $usage = $row->getAttribute('usage');
            $usage = is_array($usage) ? $usage : [];

            $trail[] = [
                'role' => (string) $row->role,
                'content' => (string) $row->content,
                'at' => $row->created_at?->toIso8601String(),
                'status' => $row->status?->value,
                'error' => $row->status === MessageStatus::Failed ? $this->errorOf($row) : null,
                // laravel/ai 1.0 names them input/output; rows written by 0.x
                // before the backfill still say prompt/completion.
                'input_tokens' => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : (isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null),
                'output_tokens' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : (isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null),
                'tools' => $tools,
            ];
        }

        return $trail;
    }

    private function errorOf(ConversationMessage $row): ?string
    {
        $meta = $row->getAttribute('meta');
        $error = is_array($meta) ? ($meta['error'] ?? null) : null;

        if (is_array($error)) {
            $error = $error['message'] ?? null;
        }

        return is_string($error) && $error !== '' ? $error : null;
    }

    /**
     * The passages a stored turn cited, recovered from the tool output.
     *
     * The format is the one `KnowledgeSearch::renderPassage()` writes:
     *
     *     [#3] Cronaca cittadina - p. 7 (score 0.71, document_id 12)
     *     ...the passage...
     *
     * The score can be negative -- cosine similarity runs from -1 to 1, and a
     * weak match is routinely below zero -- so the sign is part of the
     * pattern. Anything that does not match is skipped rather than guessed at.
     *
     * @return list<array<string, mixed>>
     */
    private function passagesFrom(ConversationMessage $row): array
    {
        $passages = [];

        foreach ($row->tool_results as $call) {
            if (! in_array($call['name'] ?? '', self::KNOWLEDGE_TOOLS, true)) {
                continue;
            }

            $output = $call['result'] ?? null;

            if (! is_string($output) || $output === '') {
                continue;
            }

            $blocks = preg_split('/\n(?=\[#\d+\])/', $output) ?: [];

            foreach ($blocks as $block) {
                if (preg_match('/^\[#(\d+)\]\s+(.*?)\s+\(score\s+(-?[0-9.]+),\s+document_id\s+(.*?)\)\n?(.*)$/s', trim($block), $matches) !== 1) {
                    continue;
                }

                $passages[] = [
                    'marker' => (int) $matches[1],
                    'label' => trim($matches[2]),
                    'document_id' => trim($matches[4]),
                    'score' => (float) $matches[3],
                    'content' => trim($matches[5]),
                    'url' => null,
                ];
            }
        }

        return $passages;
    }

    /**
     * Tool calls waiting for the user's decision, keyed by call id. Only the
     * newest turn counts, and only while it is paused: an older pause the
     * user walked away from must not block the conversation forever.
     *
     * @return array<string, array{tool: string, reason: ?string, arguments: array<string, mixed>}>
     */
    public function pendingApprovals(string $conversationId): array
    {
        $store = app(ConversationStore::class);

        $pending = $store instanceof ResolvesPendingApprovals
            ? $store->pendingApprovalsFor($conversationId)
            : $this->pendingFromRows($conversationId);

        $approvals = [];

        foreach ($pending as $approval) {
            $approvals[$approval->id] = [
                'tool' => $approval->tool,
                'reason' => $approval->reason !== null && $approval->reason !== '' ? $approval->reason : null,
                'arguments' => $approval->arguments,
            ];
        }

        return $approvals;
    }

    /**
     * The same answer for a host that bound a store of its own.
     *
     * @return list<PendingApproval>
     */
    private function pendingFromRows(string $conversationId): array
    {
        $newest = $this->rows($conversationId)->last();

        if ($newest === null || $newest->role !== 'assistant' || $newest->status !== MessageStatus::Paused) {
            return [];
        }

        $pending = [];

        foreach ($newest->tool_calls as $call) {
            if (is_array($call) && isset($call['id']) && PendingApproval::isPending($call)) {
                $pending[] = new PendingApproval(
                    (string) $call['id'],
                    (string) ($call['name'] ?? ''),
                    is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                    is_string($call['approval_reason'] ?? null) ? $call['approval_reason'] : null,
                );
            }
        }

        return $pending;
    }

    /**
     * @return Collection<int, ConversationMessage>
     */
    private function rows(string $conversationId): Collection
    {
        // Ids are UUIDv7, so ordering by id is ordering by creation even when
        // two rows share a created_at second.
        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('id')
            ->get();
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
