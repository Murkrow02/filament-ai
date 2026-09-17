<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chat;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Data\SolveOptions;
use Murkrow\FilamentAi\Models\Conversation;
use Murkrow\FilamentAi\Models\QueryCitation;
use Murkrow\FilamentAi\Models\QueryLog;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * Everything the chat page hands to the browser, in one object.
 *
 * Building it here rather than in the template is what makes the ability
 * checks real: a value the current user may not see is not rendered hidden,
 * it is never put in the payload at all. Anything the page cannot prove it is
 * allowed to show simply has no data to show.
 */
final class ChatPayload
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly AssistantTurn $turn,
    ) {}

    /**
     * @param  array{mode?: string, agent?: ?string, resource?: ?string, record?: ?string}  $options
     * @return array<string, mixed>
     */
    public function build(?Authenticatable $user, ?Conversation $current = null, array $options = []): array
    {
        $allowed = ChatAbilities::allowed($user);

        // The agent is a mode of this page, not a second page -- but only
        // where it is actually usable: the ability, rag.agent.authorize and
        // laravel/ai's tables all have to agree.
        $agent = $allowed['agent'] && $this->turn->available();
        $agentConversation = $agent ? $this->turn->ownedConversation($options['agent'] ?? null, $user) : null;
        $mode = $agent && (($options['mode'] ?? null) === 'agent' || $agentConversation !== null)
            ? 'agent'
            : 'knowledge';

        // Being allowed to pick a model means nothing when none are on offer.
        // Left as-is, the page still sent the configured model with every
        // question while the settings modal correctly hid the (empty) select,
        // and the request was rejected because that model is not in the list
        // it is validated against.
        if (empty(config('rag.llm.available_models', []))) {
            $allowed['model'] = false;
        }

        return [
            'abilities' => $allowed,
            'modes' => ['knowledge' => true, 'agent' => $agent],
            'mode' => $mode,
            'context' => $this->context($agent ? ($options['resource'] ?? null) : null, $options['record'] ?? null),
            'solving' => $this->solving($allowed, $agent),
            'persist' => $this->persists($allowed),
            'stream' => (bool) config('rag.answering.stream', true),
            'endpoints' => $this->endpoints(),
            'csrf' => csrf_token(),
            'brand' => [
                'name' => config('rag.chat.brand.name') ?: config('app.name'),
                'logo' => config('rag.chat.brand.logo'),
                'accent' => (string) config('rag.chat.brand.accent', '#2f6f4f'),
            ],
            'models' => $allowed['model'] ? (array) config('rag.llm.available_models', []) : [],
            'currentModel' => $allowed['model'] ? (string) config('rag.llm.model') : null,
            'sources' => $allowed['sources'] ? $this->sourceOptions() : [],
            'defaults' => [
                'top_k' => (int) config('rag.retrieval.top_k', 8),
                'min_score' => (float) config('rag.retrieval.min_score', 0.25),
            ],
            'suggestions' => $this->suggestions(),
            'conversations' => $allowed['history']
                ? [...$this->conversations($user), ...($agent ? $this->agentConversations($user) : [])]
                : [],
            'current' => match (true) {
                $agentConversation !== null => $this->agentConversation($agentConversation),
                $current !== null => $this->conversation($current, $allowed),
                default => null,
            },
        ];
    }

    /**
     * What the user is looking at, so "this order" resolves. The label is
     * resolved server-side against the resource's own policies: an id the
     * browser made up produces no label and no context.
     *
     * @return array{resource: ?string, record: ?string, label: ?string}
     */
    private function context(?string $resource, ?string $record): array
    {
        return $this->turn->resolvedContext($resource, $record);
    }

    /**
     * The iterative search, when it is switched on and this user may use it.
     * Null keeps the toggle off the page entirely.
     *
     * @param  array<string, bool>  $allowed
     * @return array<string, mixed>|null
     */
    private function solving(array $allowed, bool $agent): ?array
    {
        if (! $agent || ! $allowed['solve'] || ! config('rag.agent.solving.enabled', false)) {
            return null;
        }

        $options = new SolveOptions;

        return [
            'attempts_per_wave' => $options->attemptsPerWave(),
            'max_waves' => $options->maxWaves(),
            // What the user is about to spend: one agent call per attempt,
            // plus one judgement each.
            'calls' => $options->maxAgentCalls(),
        ];
    }

    /**
     * The agent's own threads, in the shape the sidebar draws.
     *
     * They are a separate store -- laravel/ai's, the only place an approval
     * pause can be resumed from -- so they carry their mode with them and the
     * page knows not to offer renaming or cost for them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function agentConversations(?Authenticatable $user): array
    {
        return array_map(static fn (array $thread): array => [
            'uuid' => $thread['id'],
            'title' => $thread['title'] !== '' ? $thread['title'] : (string) __('rag::rag.chat.untitled'),
            'mode' => 'agent',
            'pinned' => false,
            'turns' => 0,
            'cost_usd' => null,
            'last_message_at' => $thread['updated_at'],
        ], $this->turn->threads($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function agentConversation(string $conversationId): array
    {
        return [
            'uuid' => $conversationId,
            'mode' => 'agent',
            'title' => (string) __('rag::rag.assistant.title'),
            'pinned' => false,
            'turns' => 0,
            'cost_usd' => null,
            'last_message_at' => null,
            'settings' => [],
            'messages' => $this->turn->messages($conversationId),
            'pending' => $this->turn->approvalCards($conversationId),
        ];
    }


    /**
     * History needs somewhere to live. Without the query log there is no turn
     * to reopen, so the sidebar is switched off rather than shown empty.
     *
     * @param  array<string, bool>  $allowed
     */
    public function persists(array $allowed): bool
    {
        return $allowed['history'] && (bool) config('rag.retrieval.log_queries', true);
    }

    /**
     * @return array<string, string>
     */
    private function endpoints(): array
    {
        // Relative on purpose. Absolute URLs are built from APP_URL, so opening
        // the app on any other host -- 127.0.0.1, a LAN address, a tunnel --
        // sent these requests cross-origin, the session cookie was not attached
        // and `auth` answered the question with a 302 to the login page.
        return [
            // The standalone page can be switched off while the panel chat
            // keeps using the rest of these; the page knows to stay put
            // instead of navigating when there is nowhere to navigate to.
            'index' => $this->routeOrNull('rag.chat.index'),
            'store' => route('rag.chat.store', [], false),
            // ":uuid" is substituted in the browser; route() would percent-encode a placeholder.
            'show' => $this->routeOrNull('rag.chat.show', ['conversation' => '__UUID__']),
            'messages' => route('rag.chat.messages', ['conversation' => '__UUID__'], false),
            'ask' => route('rag.chat.ask', ['conversation' => '__UUID__'], false),
            'update' => route('rag.chat.update', ['conversation' => '__UUID__'], false),
            'destroy' => route('rag.chat.destroy', ['conversation' => '__UUID__'], false),
            'feedback' => route('rag.chat.feedback', ['query' => '__UUID__'], false),
            'agentMessages' => route('rag.chat.agent.messages', ['conversation' => '__UUID__'], false),
            'agentDecide' => route('rag.chat.agent.decide', ['conversation' => '__UUID__'], false),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function routeOrNull(string $name, array $parameters = []): ?string
    {
        return \Illuminate\Support\Facades\Route::has($name)
            ? route($name, $parameters, false)
            : null;
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    private function sourceOptions(): array
    {
        $options = [];

        foreach ($this->sources->options() as $key => $label) {
            $options[] = ['key' => $key, 'label' => $label];
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function suggestions(): array
    {
        $configured = (array) config('rag.chat.suggestions', []);

        if ($configured !== []) {
            return array_values(array_map(strval(...), $configured));
        }

        $default = __('rag::rag.chat.suggestions');

        return is_array($default) ? array_values(array_map(strval(...), $default)) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conversations(?Authenticatable $user): array
    {
        if (! config('rag.retrieval.log_queries', true)) {
            return [];
        }

        $query = Conversation::query();

        if (! ChatAbilities::allows('all_conversations', $user)) {
            $query->forUser($user?->getAuthIdentifier());
        }

        return $query
            // A conversation with no turn has nothing to reopen; showing it
            // means an "Untitled chat" row that does nothing when clicked.
            ->where('turns', '>', 0)
            ->recent()
            ->limit((int) config('rag.chat.max_conversations', 200))
            ->get()
            ->map(fn (Conversation $conversation): array => $this->summary($conversation))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Conversation $conversation): array
    {
        return [
            'uuid' => $conversation->uuid,
            'mode' => 'knowledge',
            'title' => $conversation->title ?: (string) __('rag::rag.chat.untitled'),
            'pinned' => (bool) $conversation->pinned,
            'turns' => (int) $conversation->turns,
            'cost_usd' => $conversation->costUsd(),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
        ];
    }

    /**
     * One conversation with its turns, filtered to what this user may see.
     *
     * @param  array<string, bool>|null  $allowed
     * @return array<string, mixed>
     */
    public function conversation(Conversation $conversation, ?array $allowed = null): array
    {
        $allowed ??= ChatAbilities::allowed();

        return $this->summary($conversation) + [
            'settings' => (array) ($conversation->settings ?? []),
            'messages' => $this->messages($conversation, $allowed),
        ];
    }

    /**
     * @param  array<string, bool>  $allowed
     * @return array<int, array<string, mixed>>
     */
    public function messages(Conversation $conversation, array $allowed): array
    {
        $queries = $conversation->queries()->with(['citations.document', 'citations.chunk'])->get();

        return $queries->map(fn (QueryLog $query): array => $this->message($query, $allowed))->all();
    }

    /**
     * @param  array<string, bool>  $allowed
     * @return array<string, mixed>
     */
    public function message(QueryLog $query, array $allowed): array
    {
        return [
            'id' => $query->uuid,
            'turn' => (int) $query->turn,
            'question' => (string) $query->question,
            'answer' => (string) ($query->answer ?? ''),
            'refused' => (bool) $query->refused,
            'model' => $allowed['model'] ? $query->llm_model : null,
            'latency_ms' => (int) ($query->latency_ms ?? 0),
            'cost_usd' => $allowed['cost'] ? $query->costUsd() : null,
            'tokens' => $allowed['cost'] ? [
                'prompt' => (int) $query->prompt_tokens,
                'completion' => (int) $query->completion_tokens,
            ] : null,
            'feedback' => $allowed['feedback'] ? $query->feedback : null,
            'passages' => $allowed['passages'] ? $this->storedPassages($query) : [],
        ];
    }

    /**
     * Passages as they were stored, in the same shape the live answer streams
     * back, so the drawer does not need two renderers.
     *
     * The snippet is what the citation table keeps -- 300 characters, not the
     * whole chunk. Reopening a conversation shows what was cited, not a second
     * copy of the corpus.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storedPassages(QueryLog $query): array
    {
        /** @var Collection<int, QueryCitation> $citations */
        $citations = $query->citations->sortBy('marker');

        return $citations->map(function (QueryCitation $citation): array {
            $document = $citation->document;
            $source = $document !== null && $this->sources->has($document->source_key)
                ? $this->sources->get($document->source_key)
                : null;

            $position = $source?->positionLabel((int) $citation->position_start, (int) $citation->position_end)
                ?? $citation->position_start.'-'.$citation->position_end;

            $title = $document?->title ?? $document?->external_id ?? '';

            return [
                'marker' => (int) $citation->marker,
                'label' => $title === '' ? $position : $title.' - '.$position,
                'score' => round((float) $citation->score, 4),
                'position_start' => (int) $citation->position_start,
                'position_end' => (int) $citation->position_end,
                'content' => (string) ($citation->snippet ?? ''),
                'url' => $source !== null && $document !== null
                    ? $source->url($document, $citation->chunk)
                    : null,
                'used' => (bool) $citation->used,
            ];
        })->values()->all();
    }
}
