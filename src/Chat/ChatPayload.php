<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chat;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Agent\Chat\PanelScope;
use Murkrow\FilamentAi\Agent\Chat\ToolLabels;
use Murkrow\FilamentAi\Data\SolveOptions;
use Murkrow\FilamentAi\Http\Controllers\AssetController;

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
        if (empty(config('filament-ai.llm.available_models', []))) {
            $allowed['model'] = false;
        }

        return [
            'abilities' => $allowed,
            'installed' => $this->turn->available(),
            'context' => $this->turn->resolvedContext($options['resource'] ?? null, $options['record'] ?? null),
            // Sent back with every request, so the turn runs in this panel and
            // tenant. See PanelScope.
            'scope' => $this->scope(),
            'version' => AssetController::version(),
            'solving' => $this->solving($allowed),
            'endpoints' => $this->endpoints(),
            'csrf' => csrf_token(),
            'brand' => [
                'name' => config('filament-ai.chat.brand.name') ?: config('app.name'),
                'logo' => config('filament-ai.chat.brand.logo'),
                'accent' => (string) config('filament-ai.chat.brand.accent', '#2f6f4f'),
            ],
            'models' => $allowed['model'] ? (array) config('filament-ai.llm.available_models', []) : [],
            'currentModel' => $allowed['model'] ? $this->currentModel() : null,
            'suggestions' => $this->suggestions(),
            'conversations' => $allowed['history'] ? $this->conversations($user) : [],
            'current' => $conversation === null ? null : $this->conversation($conversation, $allowed['debug']),
        ];
    }

    /**
     * One conversation, in the shape the page renders it.
     *
     * @return array<string, mixed>
     */
    public function conversation(string $conversationId, bool $debug = false): array
    {
        return [
            'uuid' => $conversationId,
            'title' => $this->turn->title($conversationId),
            'messages' => $this->presentMessages($this->turn->messages($conversationId), $debug),
            'pending' => $this->turn->approvalCards($conversationId, $debug),
        ];
    }

    /**
     * The stored turns, as the reader may see them: steps by their label,
     * a failed turn as a sentence, retrieval scores and raw errors only for
     * the `debug` ability -- the same rules the live stream follows.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function presentMessages(array $messages, bool $debug): array
    {
        $labels = app(ToolLabels::class);

        return array_map(static function (array $message) use ($labels, $debug): array {
            $message['tools'] = array_map(static fn (array $tool): array => array_filter([
                'id' => $tool['id'],
                'label' => $labels->label($tool['name']),
                'status' => $tool['status'],
                'name' => $debug ? $tool['name'] : null,
            ], static fn (mixed $value): bool => $value !== null), $message['tools'] ?? []);

            if ($message['failed'] ?? false) {
                $message['failure'] = (string) __('filament-ai::messages.assistant.failed');
            }

            if (! $debug) {
                unset($message['error']);

                $message['passages'] = array_map(
                    static fn (array $passage): array => array_diff_key($passage, ['score' => true, 'document_id' => true]),
                    $message['passages'] ?? [],
                );
            }

            return $message;
        }, $messages);
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
            'title' => $thread['title'] !== '' ? $thread['title'] : (string) __('filament-ai::messages.chat.untitled'),
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
        if (! $allowed['solve'] || ! config('filament-ai.agent.solving.enabled', false)) {
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
     * @return array{panel: ?string, tenant: ?string}
     */
    private function scope(): array
    {
        if (! class_exists(Filament::class)) {
            return ['panel' => null, 'tenant' => null];
        }

        return [
            'panel' => Filament::getCurrentPanel()?->getId(),
            'tenant' => PanelScope::tenantKey(),
        ];
    }

    private function currentModel(): ?string
    {
        $model = config('filament-ai.agent.model') ?? config('filament-ai.llm.model');

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
            'index' => $this->routeOrNull('filament-ai.chat.index'),
            // ":uuid" is substituted in the browser; route() would percent-encode a placeholder.
            'show' => $this->routeOrNull('filament-ai.chat.show', ['conversation' => '__UUID__']),
            'messages' => route('filament-ai.chat.messages', ['conversation' => '__UUID__'], false),
            'update' => route('filament-ai.chat.update', ['conversation' => '__UUID__'], false),
            'destroy' => route('filament-ai.chat.destroy', ['conversation' => '__UUID__'], false),
            'decide' => route('filament-ai.chat.decide', ['conversation' => '__UUID__'], false),
            'ask' => route('filament-ai.chat.ask', [], false),
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
        $configured = (array) config('filament-ai.chat.suggestions', []);

        if ($configured !== []) {
            return array_values(array_map(strval(...), $configured));
        }

        $default = __('filament-ai::messages.chat.suggestions');

        return is_array($default) ? array_values(array_map(strval(...), $default)) : [];
    }
}
