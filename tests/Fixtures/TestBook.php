<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stand-in for a host application model.
 *
 * The package must never name a real application's classes, so its own tests
 * do not either: if this fixture is enough to exercise every code path, the
 * package is genuinely generic.
 */
class TestBook extends Model
{
    protected $table = 'test_books';

    protected $guarded = [];

    /**
     * @return HasMany<TestBookPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(TestBookPage::class, 'test_book_id');
    }
}
