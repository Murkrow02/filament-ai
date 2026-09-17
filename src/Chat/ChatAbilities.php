<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chat;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * The chat page's authorization vocabulary.
 *
 * Every control on the page maps to one ability here, so "who is allowed to
 * see the cost" is answered in one place instead of being scattered through
 * a Blade template. The abilities are registered as ordinary Gate abilities
 * named `rag.chat.<name>`, which means a host can override any of them the
 * way it overrides anything else -- Gate::define, a policy, spatie's
 * permission strings -- without the package knowing about it.
 *
 * The resolution order is deliberate:
 *
 *   1. an ability the host has already defined on the Gate wins outright;
 *   2. otherwise config('rag.chat.abilities.<name>') decides;
 *   3. otherwise the default below.
 */
final class ChatAbilities
{
    public const PREFIX = 'rag.chat.';

    /**
     * Every ability, with the answer given when neither the host's Gate nor
     * config has an opinion.
     *
     * Defaults are open except `all_conversations`: the page already sits
     * behind whatever middleware the host configured, and a chat that hides
     * its own answer's sources by default would be pointless. Reading other
     * people's conversations is the one thing that must be asked for.
     *
     * @var array<string, bool>
     */
    public const DEFAULTS = [
        // Reaching the page at all.
        'view' => true,

        // The sidebar of saved conversations, and saving them in the first place.
        'history' => true,

        // Renaming, pinning and deleting one's own conversations.
        'delete' => true,

        // The model picker, and the model name under each answer.
        'model' => true,

        // The knowledge-source picker.
        'sources' => true,

        // The sources drawer and the clickable [#n] citation pills.
        'passages' => true,

        // Per-answer cost and token counts, and the conversation total.
        'cost' => true,

        // top_k, min_score and retrieval-only mode, behind the gear button.
        'advanced' => true,

        // Thumbs up / down on an answer.
        'feedback' => true,

        // Copying or downloading a conversation.
        'export' => true,

        // The agent mode: tools, writes and approvals, as opposed to answers
        // built from the knowledge base. Still subject to rag.agent.authorize:
        // this ability can only take the mode away, never grant it.
        'agent' => true,

        // The iterative search toggle in the composer. Subject to
        // rag.agent.solving.enabled the same way.
        'solve' => true,

        // Reading conversations that belong to somebody else.
        'all_conversations' => false,
    ];

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public static function ability(string $name): string
    {
        return self::PREFIX.$name;
    }

    /**
     * Register every ability that the host has not already defined itself.
     *
     * Called from the service provider's boot(), never register(): under
     * Octane the container outlives the request, and registration that runs
     * per request accumulates.
     */
    public static function register(): void
    {
        /** @var GateContract $gate */
        $gate = Gate::getFacadeRoot();

        foreach (self::names() as $name) {
            $ability = self::ability($name);

            // A host that defined this itself has said the last word.
            if ($gate->has($ability)) {
                continue;
            }

            Gate::define($ability, static fn (?Authenticatable $user = null): bool => self::resolve($name, $user));
        }
    }

    /**
     * Resolve one ability straight from config, ignoring the Gate.
     *
     * Kept separate from the Gate closure so the closure has something to call
     * and so tests can assert the config shapes without a container.
     */
    public static function resolve(string $name, ?Authenticatable $user): bool
    {
        $configured = config('rag.chat.abilities.'.$name);

        if ($configured === null) {
            return self::DEFAULTS[$name] ?? false;
        }

        if (is_bool($configured)) {
            return $configured;
        }

        // A permission name, checked before callables on purpose: is_callable()
        // is true for any string naming a global function, and a permission
        // called "viewRag" must not become a call to viewRag().
        if (is_string($configured)) {
            return $configured !== ''
                && $user !== null
                && method_exists($user, 'can')
                && (bool) $user->can($configured);
        }

        // The `fn (?Authenticatable $user): bool` shape rag.filament.authorize
        // already uses, so a host only has to learn it once.
        //
        // Note for hosts that run `config:cache`: a closure here cannot be
        // var_export()ed and will make `artisan optimize` fail. Use a
        // [Policy::class, 'method'] array instead -- it is callable and it
        // survives caching.
        if (is_callable($configured)) {
            return (bool) $configured($user);
        }

        return self::DEFAULTS[$name] ?? false;
    }

    public static function allows(string $name, ?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();

        return Gate::forUser($user)->allows(self::ability($name));
    }

    /**
     * Every ability resolved once, for the Blade template and the JSON payload
     * the page bootstraps from -- so the server and the browser can never hold
     * different opinions about what this user may see.
     *
     * @return array<string, bool>
     */
    public static function allowed(?Authenticatable $user = null): array
    {
        $user ??= Auth::user();

        $allowed = [];

        foreach (self::names() as $name) {
            $allowed[$name] = self::allows($name, $user);
        }

        return $allowed;
    }
}
