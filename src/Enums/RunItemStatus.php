<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Enums;

enum RunItemStatus: string
{
    case Pending = 'pending';
    case Skipped = 'skipped';
    case Chunked = 'chunked';
    case Embedded = 'embedded';
    case Failed = 'failed';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Skipped => 'warning',
            self::Chunked => 'info',
            self::Embedded => 'success',
            self::Failed => 'danger',
        };
    }
}
