<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Murkrow\FilamentAi\Models\Document;

final class DocumentIngested
{
    use Dispatchable;

    public function __construct(
        public readonly Document $document,
        public readonly int $chunksCreated,
        public readonly int $chunksReused,
        public readonly int $chunksDeleted,
    ) {}
}
