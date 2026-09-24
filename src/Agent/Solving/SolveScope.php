<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Solving;

use Filament\Facades\Filament;
use Murkrow\FilamentAi\Agent\Chat\PanelScope;
use Murkrow\FilamentAi\Models\SolveRun;
use Throwable;

/**
 * Carries the panel, tenant and user of a solving run from the request that
 * started it to the queue workers that run its attempts.
 */
final class SolveScope
{
    /**
     * @return array{panel: ?string, tenant: ?string, user: int|string|null}
     */
    public static function capture(): array
    {
        $panel = Filament::getCurrentPanel();

        return [
            'panel' => $panel?->getId(),
            'tenant' => PanelScope::tenantKey($panel),
            'user' => $panel?->auth()->id() ?? auth()->id(),
        ];
    }

    /**
     * Put a worker back in the run's scope. Whatever cannot be restored stays
     * unset, which leaves the resource tools off rather than unscoped.
     */
    public static function restore(SolveRun $run): void
    {
        $scope = is_array($run->scope) ? $run->scope : [];
        $panel = PanelScope::panel(is_string($scope['panel'] ?? null) ? $scope['panel'] : null);

        if ($panel === null || ! isset($scope['user'])) {
            return;
        }

        try {
            $guard = auth()->guard($panel->getAuthGuard());
            $user = $guard->getProvider()->retrieveById($scope['user']);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        if ($user === null) {
            return;
        }

        $guard->setUser($user);
        auth()->shouldUse($panel->getAuthGuard());

        PanelScope::enter($panel, $user, is_string($scope['tenant'] ?? null) ? $scope['tenant'] : null);
    }
}
