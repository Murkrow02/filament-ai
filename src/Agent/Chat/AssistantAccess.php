<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Whether the panel assistant is switched on, and whether a user may use it.
 */
final class AssistantAccess
{
    public static function enabled(): bool
    {
        return (bool) config('filament-ai.enabled', true)
            && (bool) config('filament-ai.agent.enabled', true)
            && (bool) config('filament-ai.agent.chat.enabled', true);
    }

    public static function allows(?Authenticatable $user = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $user ??= Auth::user();

        if ($user === null) {
            return false;
        }

        $callback = config('filament-ai.agent.authorize');

        return is_callable($callback) ? (bool) $callback($user) : true;
    }
}
