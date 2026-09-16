<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Murkrow\FilamentAi\Models\SolveRun;

final class SolveWaveCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly SolveRun $run,
        public readonly int $wave,
        public readonly bool $solved,
    ) {}
}
