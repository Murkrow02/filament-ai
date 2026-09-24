<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An agent tool failed in a way the model cannot fix: a database error, a bug.
 *
 * Reported with a short reference that the tool also hands the model, so the
 * line a user quotes from the chat leads straight to the log entry -- while
 * the exception's own message, which can carry SQL and values, never reaches
 * the language model's provider.
 */
final class ToolFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $reference,
        public readonly string $tool,
        Throwable $previous,
    ) {
        parent::__construct("Agent tool [{$tool}] failed (ref {$reference}): ".$previous->getMessage(), 0, $previous);
    }
}
