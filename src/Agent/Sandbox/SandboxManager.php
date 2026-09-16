<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Sandbox;

use Closure;
use Illuminate\Support\Manager;
use Murkrow\FilamentAi\Contracts\CodeSandbox;

/**
 * @method CodeSandbox driver(string|null $driver = null)
 */
final class SandboxManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('rag.agent.sandbox.driver', 'piston');
    }

    public function createPistonDriver(): CodeSandbox
    {
        return PistonSandbox::fromConfig();
    }

    public function createFakeDriver(): CodeSandbox
    {
        return new FakeSandbox(array_keys((array) $this->config->get('rag.agent.sandbox.languages', ['python' => '*'])));
    }

    /**
     * Register a sandbox of your own, e.g. a hosted service.
     *
     * @param  Closure(\Illuminate\Contracts\Container\Container): CodeSandbox  $callback
     */
    public function register(string $name, Closure $callback): self
    {
        $this->extend($name, $callback);

        return $this;
    }
}
