<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chat;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Data\SolveOptions;

/**
 * Everything the chat hands to the browser, in one object.
 *
 * Building it here rather than in the template is what makes the ability
 * checks real: a value the current user may not see is not rendered hidden,
 * it is never put in the payload at all. Anything the page cannot prove it is
 * allowed to show simply has no data to show.
 *
 * There is one kind of conversation: the assistant's, stored by laravel/ai,
 * which is the only place a turn paused for approval can resume from.
 */
final class ChatPayload
{
    public function __construct(
        private readonly AssistantTurn $turn,
    ) {}

    /**
     * @param  array{conversation?: ?string, resource?: ?string, record?: ?string}  $options
     * @return array<string, mixed>
     */
    public function build(?Authenticatable $user, array $options = []): array
    {
        $allowed = ChatAbilities::allowed($user);
        $conversation = $this->turn->ownedConversation($options['conversation'] ?? null, $user);

        // Being allowed to pick a model means nothing when none are on offer.
        if (empty(config('rag.llm.available_models', []))) {
            $allowed['model'] = false;
        }

        return [
            'abilities' => $allowed,
            'installed' => $this->turn->available(),
            'context' => $this->turn->resolvedContext($options['resource'] ?? null, $options['record'] ?? null),
            'solving' => $this->solving($allowed),
            'endpoints' => $this->endpoints(),
            'csrf' => csrf_token(),
            'brand' => [
                'name' => config('rag.chat.brand.name') ?: config('app.name'),
                'logo' => config('rag.chat.brand.logo'),
                'accent' => (string) config('rag.chat.brand.accent', '#2f6f4f'),
            ],
            'models' => $allowed['model'] ? (array) config('rag.llm.available_models', []) : [],
            'currentModel' => $allowed['model'] ? $this->currentModel() : null,
            'suggestions' => $this->suggestions(),
            'conversations' => $allowed['history'] ? $this->conversations($user) : [],
            'current' => $conversation === null ? null : $this->conversation($conversation),
        ];
    }

    /**
     * One conversation, in the shape the page renders it.
     *
     * @return array<string, mixed>
     */
    public function conversation(string $conversationId): array
    {
        return [
            'uuid' => $conversationId,
            'title' => $this->turn->title($conversationId),
            'messages' => $this->turn->messages($conversationId),
            'pending' => $this->turn->approvalCards($conversationId),
        ];
    }

    /**
     * The user's own threads, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conversations(?Authenticatable $user): array
    {
        return array_map(static fn (array $thread): array => [
            'uuid' => $thread['id'],
            'title' => $thread['title'] !== '' ? $thread['title'] : (string) __('rag::rag.chat.untitled'),
            'last_message_at' => $thread['updated_at'],
        ], $this->turn->threads($user));
    }

    /**
     * The iterative search, when it is switched on and this user may use it.
     * Null keeps the toggle off the page entirely.
     *
     * @param  array<string, bool>  $allowed
     * @return array<string, mixed>|null
     */
    private function solving(array $allowed): ?array
    {
        if (! $allowed['solve'] || ! config('rag.agent.solving.enabled', false)) {
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

    private function currentModel(): ?string
    {
        $model = config('rag.agent.model') ?? config('rag.llm.model');

        return blank($model) ? null : (string) $model;
    }

    /**
     * @return array<string, ?string>
     */
    private function endpoints(): array
    {
        // Relative on purpose. Absolute URLs are built from APP_URL, so opening
        // the app on any other host -- 127.0.0.1, a LAN address, a tunnel --
        // sent these requests cross-origin, the session cookie was not attached
        // and `auth` answered the question with a 302 to the login page.
        return [
            // The standalone page can be switched off while the panel's chat
            // keeps using the rest of these, so the page must cope with having
            // nowhere to navigate to.
            'index' => $this->routeOrNull('rag.chat.index'),
            // ":uuid" is substituted in the browser; route() would percent-encode a placeholder.
            'show' => $this->routeOrNull('rag.chat.show', ['conversation' => '__UUID__']),
            'messages' => route('rag.chat.messages', ['conversation' => '__UUID__'], false),
            'update' => route('rag.chat.update', ['conversation' => '__UUID__'], false),
            'destroy' => route('rag.chat.destroy', ['conversation' => '__UUID__'], false),
            'decide' => route('rag.chat.decide', ['conversation' => '__UUID__'], false),
            'ask' => route('rag.chat.ask', [], false),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function routeOrNull(string $name, array $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters, false) : null;
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
}
