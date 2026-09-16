<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Murkrow\FilamentAi\Models\IngestionRun;
use Throwable;

final class IngestionRunFailed
{
    use Dispatchable;

    public function __construct(
        public readonly IngestionRun $run,
        public readonly ?Throwable $exception = null,
    ) {}
}
