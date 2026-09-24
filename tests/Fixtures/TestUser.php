<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;

/**
 * A panel user, as a host's User model is: Filament lets only a
 * `FilamentUser` into a panel outside local development, and so does the
 * agent's resource access.
 */
class TestUser extends User implements FilamentUser, HasTenants
{
    protected $table = 'users';

    protected $guarded = [];

    /** Flipped by tests to lock a user out of the panel. */
    public static bool $panelAccess = true;

    /** @var list<int> Ids of the teams this user belongs to. */
    public static array $teams = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return static::$panelAccess;
    }

    public function getTenants(Panel $panel): Collection
    {
        return TestTeam::query()->whereKey(static::$teams)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof TestTeam && in_array($tenant->getKey(), static::$teams, true);
    }
}
