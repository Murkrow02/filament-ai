<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The panel -- and, on a tenant panel, the tenant -- the assistant acts in.
 *
 * Filament's tenant isolation is not a property of a resource: the global
 * scope and the `creating` observer that keep one tenant's records away from
 * another are registered when a panel boots, and apply only while that panel
 * is the current one and a tenant is set. The chat's routes live outside the
 * panel's route group, so without this nothing is current, nothing is scoped,
 * and every resource tool reads and writes across tenants.
 *
 * `enter()` does what Filament's own `SetUpPanel` and `IdentifyTenant`
 * middleware do. `allowsResources()` is what the resource tools ask before
 * they are offered at all: a user who could not open the panel, or a tenant
 * panel with no tenant set, gets none.
 */
final class PanelScope
{
    /**
     * @param  Model|string|int|null  $tenant  a tenant, or the key the page sent
     */
    public static function enter(Panel $panel, ?Authenticatable $user, Model|string|int|null $tenant = null): void
    {
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();

        // Policies are evaluated for whoever the default guard returns: make
        // that the panel's own user, as the panel's routes do.
        $guard = $panel->getAuthGuard();

        if (auth()->guard($guard)->check()) {
            auth()->shouldUse($guard);
            $user = auth()->guard($guard)->user();
        }

        if (! $panel->hasTenancy() || ! $user instanceof HasTenants) {
            return;
        }

        $resolved = self::tenant($panel, $user, $tenant);

        if ($resolved !== null) {
            Filament::setTenant($resolved, isQuiet: true);
        }
    }

    /**
     * Resolve a panel from the id a page sent, falling back to the configured
     * one and then to the default. An id that names no panel is ignored.
     */
    public static function panel(?string $id): ?Panel
    {
        foreach ([$id, config('filament-ai.chat.panel')] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                return Filament::getPanel($candidate, isStrict: true);
            } catch (Throwable) {
                continue;
            }
        }

        try {
            return Filament::getDefaultPanel();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the resource tools may act for this user in this panel.
     */
    public static function allowsResources(?Panel $panel, ?Authenticatable $user = null): bool
    {
        if ($panel === null) {
            return false;
        }

        $user ??= $panel->auth()->user() ?? auth()->user();

        if ($user === null) {
            return false;
        }

        // Exactly Filament's own panel gate (`Authenticate` middleware).
        if ($user instanceof FilamentUser ? ! $user->canAccessPanel($panel) : ! app()->isLocal()) {
            return false;
        }

        if (! $panel->hasTenancy()) {
            return true;
        }

        $tenant = Filament::getTenant();

        return $tenant !== null
            && Filament::getCurrentPanel() === $panel
            && $user instanceof HasTenants
            && $user->canAccessTenant($tenant);
    }

    /**
     * The key a page sends to name its tenant, as the panel's URLs carry it.
     */
    public static function tenantKey(?Panel $panel = null, ?Model $tenant = null): ?string
    {
        $panel ??= Filament::getCurrentPanel();
        $tenant ??= Filament::getTenant();

        if ($panel === null || $tenant === null || ! $panel->hasTenancy()) {
            return null;
        }

        $value = $tenant->getAttributeValue($panel->getTenantSlugAttribute() ?? $tenant->getRouteKeyName());

        return $value === null ? null : (string) $value;
    }

    private static function tenant(Panel $panel, HasTenants&Authenticatable $user, Model|string|int|null $tenant): ?Model
    {
        if ($tenant !== null && $tenant !== '' && ! $tenant instanceof Model) {
            // A tenant the page named and this user cannot access is refused,
            // not swapped for another one: an answer about a different team
            // would be as wrong as a leak.
            try {
                $tenant = $panel->getTenant((string) $tenant);
            } catch (Throwable) {
                return null;
            }
        }

        if ($tenant === null || $tenant === '') {
            // The standalone page has no tenant in its URL: act in the one the
            // panel itself would have opened.
            try {
                $tenant = Filament::getUserDefaultTenant($user);
            } catch (Throwable) {
                $tenant = null;
            }
        }

        return $tenant instanceof Model && $user->canAccessTenant($tenant) ? $tenant : null;
    }
}
