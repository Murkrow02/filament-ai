<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Exceptions;

class UnknownSourceException extends RagException
{
    /**
     * @param  array<int, string>  $known
     */
    public static function for(string $key, array $known = []): self
    {
        $hint = $known === [] ? 'none registered' : implode(', ', $known);

        return new self("Unknown knowledge source [{$key}]. Registered sources: {$hint}.");
    }
}
