<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Murkrow\FilamentAi\Agent\Solving\DefaultStrategy;
use Murkrow\FilamentAi\Contracts\Verifier;

/**
 * The default method, judged by something that already knows the answer.
 */
class KnownAnswerStrategy extends DefaultStrategy
{
    public function verifier(): ?Verifier
    {
        return new KnownAnswerVerifier('Roma');
    }
}
