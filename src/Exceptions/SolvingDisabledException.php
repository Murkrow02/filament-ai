<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Exceptions;

use RuntimeException;

/**
 * Iterative solving multiplies the cost of an answer, so it never starts by
 * accident: a host switches it on knowingly.
 */
final class SolvingDisabledException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Iterative solving is switched off. Set rag.agent.solving.enabled (RAG_AGENT_SOLVING=true) to use it.');
    }
}
