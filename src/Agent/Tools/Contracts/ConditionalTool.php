<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools\Contracts;

/**
 * A host tool that is offered only to some users, or only in some setups.
 * Left out of the turn when unavailable, so the model is not told about
 * something it cannot use; the tool still checks again when it runs.
 */
interface ConditionalTool
{
    public function available(): bool;
}
