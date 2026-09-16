<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chat;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?Authenticatable $user, ?Conversation $current = null): array
    {
        $allowed = ChatAbilities::allowed($user);

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
            'conversations' => $allowed['history'] ? $this->conversations($user) : [],
            'current' => $current === null ? null : $this->conversation($current, $allowed),
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
            'index' => route('rag.chat.index', [], false),
            'store' => route('rag.chat.store', [], false),
            // ":uuid" is substituted in the browser; route() would percent-encode a placeholder.
            'show' => route('rag.chat.show', ['conversation' => '__UUID__'], false),
            'messages' => route('rag.chat.messages', ['conversation' => '__UUID__'], false),
            'ask' => route('rag.chat.ask', ['conversation' => '__UUID__'], false),
            'update' => route('rag.chat.update', ['conversation' => '__UUID__'], false),
            'destroy' => route('rag.chat.destroy', ['conversation' => '__UUID__'], false),
            'feedback' => route('rag.chat.feedback', ['query' => '__UUID__'], false),
        ];
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
