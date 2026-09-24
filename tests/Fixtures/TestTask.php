<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record owned by a tenant.
 */
class TestTask extends Model
{
    protected $table = 'test_tasks';

    protected $guarded = [];

    /**
     * @return BelongsTo<TestTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(TestTeam::class, 'test_team_id');
    }
}
