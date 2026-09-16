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
 */
final class AuthorizeRagChat
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('rag.enabled', true) && config('rag.chat.enabled', true), 404);
        abort_unless(ChatAbilities::allows('view', $request->user()), 403);

        return $next($request);
    }
}
