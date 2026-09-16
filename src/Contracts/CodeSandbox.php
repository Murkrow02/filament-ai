<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Murkrow\FilamentAi\Agent\Sandbox\SandboxResult;

/**
 * Runs a snippet the model wrote, somewhere it cannot do harm.
 *
 * The contract says nothing about where that is: the shipped driver talks to a
 * self-hosted Piston, and a host can bind its own. Whatever implements it is
 * the security boundary, and is expected to enforce the limits itself -- no
 * network, no filesystem the application shares, a wall-clock timeout and a
 * memory cap. The tool above it only truncates and reports.
 */
interface CodeSandbox
{
    /**
     * Languages this sandbox will accept, as names the model may pass.
     *
     * @return list<string>
     */
    public function languages(): array;

    public function run(string $language, string $code, string $stdin = ''): SandboxResult;
}
