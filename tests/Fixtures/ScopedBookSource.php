<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Murkrow\FilamentAi\Contracts\ScopesDocumentsToUser;

/**
 * The books source, readable per user: each user sees the books listed for
 * them, and a guest sees none.
 */
class ScopedBookSource extends TestBookSource implements ScopesDocumentsToUser
{
    /** @var array<int|string, list<string>> user id => readable external ids */
    public static array $readable = [];

    public static bool $broken = false;

    public function scopeDocumentsFor(Builder $documents, ?Authenticatable $user, string $table): void
    {
        if (static::$broken) {
            throw new \RuntimeException('The scope could not be built.');
        }

        $documents->whereIn("{$table}.external_id", static::$readable[$user?->getAuthIdentifier()] ?? []);
    }
}
