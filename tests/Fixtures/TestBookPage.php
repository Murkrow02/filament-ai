<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestBookPage extends Model
{
    protected $table = 'test_book_pages';

    protected $guarded = [];

    /**
     * @return BelongsTo<TestBook, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(TestBook::class, 'test_book_id');
    }
}
