<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every answer of the token API is JSON or an event stream, never a redirect.
 *
 * A client asking for a stream sends `Accept: text/event-stream`, which Laravel
 * does not count as expecting JSON: an expired token was then redirected to a
 * login page the client cannot show, and an abort() rendered an HTML error
 * page. Placed before the host's own middleware, so `auth` sees it too.
 */
final class AcceptsJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
