<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The tenant of the tenant panel fixture.
 */
class TestTeam extends Model
{
    protected $table = 'test_teams';

    protected $guarded = [];
}
