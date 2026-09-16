<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Enums;

/**
 * What became of one attempt.
 *
 * `Unjudged` is its own state on purpose: an attempt whose verifier threw is
 * not a rejected attempt, and counting it as one would let a broken judge
 * quietly discard good answers.
 */
enum SolveAttemptStatus: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Unjudged = 'unjudged';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Answered => 'Answered',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Unjudged => 'Not judged',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Answered => 'info',
            self::Accepted => 'success',
            self::Rejected => 'warning',
            self::Unjudged, self::Failed => 'danger',
        };
    }
}
