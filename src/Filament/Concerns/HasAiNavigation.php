<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Concerns;

use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Shared navigation and access rules for every RAG page and resource.
 *
 * The navigation group has to be resolved in a method rather than a static
 * property: config is not available at property-initialisation time, and a
 * host that folds these pages into an existing group would otherwise have to
 * subclass every one of them.
 */
trait HasAiNavigation
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = config('filament-ai.filament.navigation_group', 'Knowledge');

        return $group === null || $group === '' ? null : (string) $group;
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('filament-ai.filament.navigation_sort', 90);
    }

    public static function canAccessAi(): bool
    {
        $callback = config('filament-ai.filament.authorize');

        if (! is_callable($callback)) {
            return true;
        }

        return (bool) $callback(Auth::user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccessAi();
    }

    protected static function aiSlug(string $suffix): string
    {
        $prefix = trim((string) config('filament-ai.filament.slug_prefix', 'ai'), '/');

        return $prefix === '' ? $suffix : $prefix.'/'.$suffix;
    }

    protected static function aiPollInterval(): ?string
    {
        $interval = config('filament-ai.filament.poll_interval', '5s');

        return $interval === null || $interval === '' ? null : (string) $interval;
    }

    /**
     * Poll only while a run is actually in flight.
     *
     * Continuous polling on an idle page is not just wasted queries: several
     * Livewire components refreshing at once race against the panel's
     * AuthenticateSession middleware, which can regenerate the session mid-flight
     * and leave the other in-flight requests holding a stale CSRF token. The
     * browser then shows "This page has expired", and refreshing re-arms the same
     * pollers -- a loop. An idle dashboard has nothing to poll for anyway.
     */
    protected static function aiPollIntervalWhileRunning(): ?string
    {
        $interval = static::aiPollInterval();

        if ($interval === null) {
            return null;
        }

        return \Murkrow\FilamentAi\Models\IngestionRun::query()->running()->exists()
            ? $interval
            : null;
    }
}
