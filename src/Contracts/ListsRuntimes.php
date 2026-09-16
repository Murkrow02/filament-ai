<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

/**
 * A sandbox that can say which languages it actually has installed.
 *
 * Optional, like `ResizableVectorStore`: a driver that cannot answer stays a
 * perfectly good `CodeSandbox`, and the settings page falls back to whatever
 * the configuration names. It exists so an administrator picks from what is
 * really there instead of typing a version that fails at the first run.
 */
interface ListsRuntimes
{
    /**
     * @return array<string, string> language name => installed version
     */
    public function runtimes(): array;
}
