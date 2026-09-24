<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chat;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Murkrow\FilamentAi\Agent\Chat\AssistantAccess;

/**
 * The chat's authorization vocabulary.
 *
 * Every control on the page maps to one ability here, so "who may pick the
 * model" is answered in one place instead of being scattered through a Blade
 * template. The abilities are registered as ordinary Gate abilities named
 * `filament-ai.chat.<name>`, which means a host can override any of them the way it
 * overrides anything else -- Gate::define, a policy, spatie's permission
 * strings -- without the package knowing about it.
 *
 * The resolution order is deliberate:
 *
 *   1. an ability the host has already defined on the Gate wins outright;
 *   2. otherwise config('filament-ai.chat.abilities.<name>') decides;
 *   3. otherwise the default below.
 */
final class ChatAbilities
{
    public const PREFIX = 'filament-ai.chat.';

    /**
     * Every ability, with the answer given when neither the host's Gate nor
     * config has an opinion.
     *
     * What a person needs to use the chat is open by default: the chat already
     * sits behind whatever middleware the host configured, and it is gated
     * again by `filament-ai.agent.authorize`. What only an administrator or a
     * developer should see -- what a turn cost, which model answered, the raw
     * error behind a failure -- has to be granted.
     *
     * @var array<string, bool>
     */
    public const DEFAULTS = [
        // Reaching the chat at all.
        'view' => true,

        // The sidebar of saved conversations, and saving them in the first place.
        'history' => true,

        // Renaming and deleting one's own conversations.
        'delete' => true,

        // The model picker, when models are on offer.
        'model' => true,

        // The settings panel itself. Denied, there is no gear button at all,
        // whatever else is allowed inside it.
        'settings' => true,

        // What each answer cost.
        'cost' => false,

        // The "keep trying" toggle: waves of attempts instead of one answer.
        // It multiplies what a question costs, which is why it is its own
        // ability rather than a corner of `settings`.
        'solve' => true,

        // Copying an answer out.
        'export' => true,

        // The technical side of a turn: the raw error behind a failure, the
        // tool names and arguments behind the plain-language steps and
        // approval cards, the model, token counts, retrieval scores and the
        // link to a solving run's attempts. Everyone else gets a sentence
        // they can act on.
        'debug' => false,
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
        $configured = config('filament-ai.chat.abilities.'.$name);

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

        // The `fn (?Authenticatable $user): bool` shape filament-ai.filament.authorize
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

    /**
     * Whether this user may use the chat at all, through either door.
     *
     * The standalone page and the panel's assistant render the same component
     * and talk to the same endpoints, but they are gated differently: the page
     * by `view`, the panel page by `filament-ai.agent.authorize`. The
     * transport behind them -- the stylesheet, the script, asking, deciding,
     * the thread list -- has to answer to both, or closing one door takes the
     * other's assets with it and the panel renders an unstyled, dead page.
     */
    public static function canUseChat(?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();

        return self::allows('view', $user) || AssistantAccess::allows($user);
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
