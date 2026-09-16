<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Chunking = 'chunking';
    case Embedding = 'embedding';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function isRunning(): bool
    {
        return ! $this->isTerminal();
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Chunking => 'Chunking',
            self::Embedding => 'Embedding',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Filament badge colour name.
     */
    public function color(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Chunking, self::Embedding => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Queued => 'heroicon-o-clock',
            self::Chunking => 'heroicon-o-scissors',
            self::Embedding => 'heroicon-o-cpu-chip',
            self::Completed => 'heroicon-o-check-circle',
            self::Failed => 'heroicon-o-x-circle',
            self::Cancelled => 'heroicon-o-no-symbol',
        };
    }
}
