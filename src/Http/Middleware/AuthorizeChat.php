<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single gate in front of every chat route.
 *
 * Applied to the group rather than repeated per action, so adding a route
 * cannot accidentally add an unguarded one.
 *
 * These routes are the transport for the panel chat as well as for the
 * standalone page, so they stay up while either one is on: switching the
 * standalone page off removes the page, not the assistant.
 */
final class AuthorizeChat
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('filament-ai.enabled', true), 404);
        abort_unless(
            config('filament-ai.chat.enabled', true)
                || (config('filament-ai.agent.enabled', true) && config('filament-ai.agent.chat.enabled', true)),
            404,
        );
        abort_unless(ChatAbilities::allows('view', $request->user()), 403);

        return $next($request);
    }
}
