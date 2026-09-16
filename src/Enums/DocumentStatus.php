<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Enums;

enum DocumentStatus: string
{
    /** Known to the registry but never chunked. */
    case Pending = 'pending';

    /** Chunked; some chunks still lack a vector. */
    case Chunked = 'chunked';

    /** Every chunk has a vector for the current model. */
    case Embedded = 'embedded';

    /** Source content or chunking parameters changed since the last run. */
    case Stale = 'stale';

    case Failed = 'failed';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Chunked => 'info',
            self::Embedded => 'success',
            self::Stale => 'warning',
            self::Failed => 'danger',
        };
    }
}
