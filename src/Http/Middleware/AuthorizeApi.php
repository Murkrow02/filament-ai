<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Murkrow\FilamentAi\Agent\Chat\AssistantAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single gate in front of every token API route.
 *
 * The API is the panel assistant over another transport, so it answers to
 * the same switches (`filament-ai.agent.*`, `agent.authorize`) and then to its
 * own: `filament-ai.api.enabled`, checked here as well as at registration so
 * a runtime change takes effect, and `filament-ai.api.authorize`.
 */
final class AuthorizeApi
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('filament-ai.enabled', true) && config('filament-ai.api.enabled', false), 404);

        $user = $request->user();
        $callback = config('filament-ai.api.authorize');

        if (! AssistantAccess::allows($user) || (is_callable($callback) && ! $callback($user))) {
            return response()->json(['message' => __('filament-ai::messages.chat.forbidden')], 403);
        }

        return $next($request);
    }
}
