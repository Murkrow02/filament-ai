<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Enums;

/**
 * How an iterative run ended, or how far it has got.
 *
 * `Exhausted` is not a failure: the agent tried within the budget it was
 * given and nothing passed the verifier. The best attempt is still there, and
 * so is the reason it was not accepted -- that is what the user is told.
 * `Failed` means the machinery broke.
 */
enum SolveStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Solved = 'solved';
    case Exhausted = 'exhausted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Solved, self::Exhausted, self::Failed, self::Cancelled], true);
    }

    public function isRunning(): bool
    {
        return ! $this->isTerminal();
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Solved => 'Solved',
            self::Exhausted => 'No solution found',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Running => 'info',
            self::Solved => 'success',
            self::Exhausted => 'warning',
            self::Failed, self::Cancelled => 'danger',
        };
    }
}
