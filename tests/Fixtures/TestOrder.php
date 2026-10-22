<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sale of a book, for the metrics: an amount in cents, a quantity, a date.
 */
class TestOrder extends Model
{
    protected $table = 'test_orders';

    protected $guarded = [];

    protected $casts = ['ordered_at' => 'datetime'];

    /**
     * @return BelongsTo<TestBook, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(TestBook::class, 'test_book_id');
    }
}
