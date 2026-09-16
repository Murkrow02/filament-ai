<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Murkrow\FilamentAi\Models\SolveRun;

final class SolveRunFinished
{
    use Dispatchable;

    public function __construct(public readonly SolveRun $run) {}
}
