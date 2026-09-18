<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Exceptions;

class IngestionException extends FilamentAiException
{
    public static function documentNotFound(string $source, string $externalId): self
    {
        return new self("Document [{$externalId}] no longer exists in source [{$source}].");
    }
}
