<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Murkrow\FilamentAi\Models\IngestionRun;

final class IngestionRunFinished
{
    use Dispatchable;

    public function __construct(public readonly IngestionRun $run) {}
}
