<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Murkrow\FilamentAi\Agent\Chat\PanelScope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the chat's requests run inside the panel -- and tenant -- the page
 * belongs to, the way the panel's own routes do. See `PanelScope`.
 *
 * The page sends `panel` and `tenant`; both are hints. The panel must exist,
 * and a tenant is only set when the user may access it. What cannot be
 * resolved leaves the resource tools off rather than unscoped.
 */
final class BootAssistantPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        if (class_exists(Filament::class)) {
            $panelId = $request->input('panel', $request->query('panel'));
            $panel = PanelScope::panel(is_string($panelId) ? $panelId : null);

            if ($panel !== null) {
                $tenant = $request->input('tenant', $request->query('tenant'));

                PanelScope::enter($panel, $request->user(), is_scalar($tenant) ? (string) $tenant : null);
            }
        }

        return $next($request);
    }
}
