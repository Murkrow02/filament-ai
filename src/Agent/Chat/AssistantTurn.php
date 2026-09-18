<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;

/**
 * One turn of the panel assistant, without a Livewire component around it.
 *
 * This is what the chat page used to do inside `AssistantChat`: resolve the
 * conversation, the panel and the record on screen, then prompt. Moving it
 * here is what lets the same turn be served over HTTP to a page that is not
 * built out of Filament components.
 *
 * Nothing here trusts the browser. The conversation must belong to the user,
 * the record must pass its resource's own query and view policy, and a
 * decision is only accepted for a call that is actually waiting for one.
 */
final class AssistantTurn
{
    public function __construct(
        private readonly ConversationTranscript $transcript,
    ) {}

    public function available(): bool
    {
        return AssistantAccess::allows() && $this->transcript->available();
    }

    /**
     * The conversation id, if this user owns it. A thread that is not theirs
     * -- or no longer exists -- silently becomes a new one, exactly as the
     * knowledge mode does with a stale thread.
     */
    public function ownedConversation(?string $conversationId, ?Authenticatable $user): ?string
    {
        if ($conversationId === null || $conversationId === '' || $user === null) {
            return null;
        }

        return $this->transcript->available() && $this->transcript->owns($conversationId, $user)
            ? $conversationId
            : null;
    }

    /**
     * The assistant, pointed at the conversation and at the page the user is
     * looking at.
     */
    public function assistant(?Authenticatable $user, ?string $conversationId, ?string $resourceSlug = null, ?string $recordKey = null): PanelAssistant
    {
        /** @var PanelAssistant $assistant */
        $assistant = app((string) config('rag.agent.assistant', PanelAssistant::class));

        $assistant = $conversationId === null
            ? $assistant->forUser($user)
            : $assistant->continue($conversationId, as: $user);

        [$resource, $record] = $this->context($resourceSlug, $recordKey);

        return $assistant->inPanel(Filament::getCurrentOrDefaultPanel())->onPage($resource, $record);
    }

    /**
     * @return array<string, array{tool: string, reason: ?string, arguments: array<string, mixed>}>
     */
    public function pending(?string $conversationId): array
    {
        return $conversationId === null ? [] : $this->transcript->pendingApprovals($conversationId);
    }

    /**
     * What is waiting, in the shape the page draws an approval card from --
     * the same whether it arrives in the stream, at the end of a turn or with
     * a reloaded conversation.
     *
     * @return list<array{id: string, tool: string, reason: ?string, arguments: array<string, string>}>
     */
    public function approvalCards(?string $conversationId): array
    {
        $cards = [];

        foreach ($this->pending($conversationId) as $id => $call) {
            $cards[] = self::card((string) $id, $call['tool'], $call['reason'], $call['arguments']);
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{id: string, tool: string, reason: ?string, arguments: array<string, string>}
     */
    public static function card(string $id, string $tool, ?string $reason, array $arguments): array
    {
        $shown = [];

        foreach ($arguments as $key => $value) {
            // Every argument is shown as text and cut short: it is what the
            // user approves, not a place to dump a document.
            $shown[(string) $key] = \Illuminate\Support\Str::limit(
                is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE),
                200,
            );
        }

        return ['id' => $id, 'tool' => $tool, 'reason' => $reason, 'arguments' => $shown];
    }

    /**
     * @return list<array{role: string, content: string, tools: list<array{id: string, name: string, status: string}>}>
     */
    public function messages(?string $conversationId): array
    {
        return $conversationId === null ? [] : $this->transcript->messages($conversationId);
    }

    public function title(?string $conversationId): ?string
    {
        return $conversationId === null ? null : $this->transcript->title($conversationId);
    }

    /**
     * Rename a thread the user owns. Null when it is not theirs.
     */
    public function rename(string $conversationId, ?Authenticatable $user, string $title): ?string
    {
        return $user === null ? null : $this->transcript->rename($conversationId, $user, $title);
    }

    /**
     * Delete a thread the user owns, its messages included.
     */
    public function delete(string $conversationId, ?Authenticatable $user): bool
    {
        return $user !== null && $this->transcript->delete($conversationId, $user);
    }

    /**
     * @return list<array{id: string, title: string, updated_at: ?string}>
     */
    public function threads(?Authenticatable $user): array
    {
        if ($user === null || ! $this->transcript->available()) {
            return [];
        }

        return $this->transcript->recent($user, (int) config('rag.agent.chat.history', 20));
    }

    /**
     * Turn what the browser sent into decisions laravel/ai can resume with.
     *
     * Every call that is waiting must be decided, and a call that is not
     * waiting is ignored: the turn resumes from the store, so a decision on
     * something else would either do nothing or resume the wrong pause.
     *
     * @param  array<string, mixed>  $raw
     */
    public function decisions(string $conversationId, array $raw): ?Decisions
    {
        $pending = $this->transcript->pendingApprovals($conversationId);

        if ($pending === []) {
            return null;
        }

        $decisions = [];

        foreach (array_keys($pending) as $id) {
            if (! array_key_exists($id, $raw)) {
                return null;
            }

            $decisions[$id] = filter_var($raw[$id], FILTER_VALIDATE_BOOLEAN)
                ? Decision::approve()
                : Decision::reject((string) __('rag::rag.assistant.rejected_reason'));
        }

        return Decisions::from($decisions);
    }

    /**
     * What the user is looking at, as far as they are allowed to look at it.
     *
     * Only what survived the checks comes back: a record key the user may not
     * view is dropped, and the page is handed the resource alone -- never the
     * key it sent, which the browser would otherwise keep posting.
     *
     * @return array{resource: ?string, record: ?string, label: ?string}
     */
    public function resolvedContext(?string $resourceSlug, ?string $recordKey): array
    {
        [$resource, $record] = $this->context($resourceSlug, $recordKey);

        if ($resource === null) {
            return ['resource' => null, 'record' => null, 'label' => null];
        }

        if ($record === null) {
            return [
                'resource' => $resourceSlug,
                'record' => null,
                'label' => (string) $resource::getPluralModelLabel(),
            ];
        }

        return [
            'resource' => $resourceSlug,
            'record' => (string) $record->getKey(),
            'label' => (string) __('rag::rag.assistant.context', [
                'label' => $resource::getModelLabel(),
                'title' => RecordPresenter::titleFor($resource, $record) ?? $record->getKey(),
            ]),
        ];
    }

    /**
     * @return array{0: class-string<\Filament\Resources\Resource>|null, 1: Model|null}
     */
    private function context(?string $resourceSlug, ?string $recordKey): array
    {
        if ($resourceSlug === null || $resourceSlug === '') {
            return [null, null];
        }

        $resource = PageContext::resourceForSlug($resourceSlug);

        if ($resource === null || ! $resource::canViewAny()) {
            return [null, null];
        }

        if ($recordKey === null || $recordKey === '') {
            return [$resource, null];
        }

        $record = $resource::getEloquentQuery()->whereKey($recordKey)->first();

        return [$resource, $record !== null && $resource::canView($record) ? $record : null];
    }
}
