<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model whose resource form uses the field behaviours the agent must honour:
 * conditional visibility, per-operation disabling, dehydration hooks and a
 * scoped relationship select.
 */
class TestArticle extends Model
{
    protected $table = 'test_articles';

    protected $guarded = [];

    protected $hidden = ['password'];

    protected $casts = ['published_on' => 'date'];

    /**
     * @return BelongsTo<TestBook, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(TestBook::class, 'test_book_id');
    }
}
