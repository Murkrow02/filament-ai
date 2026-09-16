<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Sandbox;

use Murkrow\FilamentAi\Contracts\CodeSandbox;

/**
 * Offline stand-in. Queue results with `respondWith()`; anything not queued
 * echoes the snippet back, which is enough for the tool's own assertions.
 */
final class FakeSandbox implements CodeSandbox
{
    /** @var list<SandboxResult> */
    private array $queue = [];

    /** @var list<array{language: string, code: string, stdin: string}> */
    private array $received = [];

    /**
     * @param  list<string>  $languages
     */
    public function __construct(private readonly array $languages = ['python']) {}

    public function respondWith(SandboxResult ...$results): self
    {
        foreach ($results as $result) {
            $this->queue[] = $result;
        }

        return $this;
    }

    /**
     * @return list<array{language: string, code: string, stdin: string}>
     */
    public function received(): array
    {
        return $this->received;
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return $this->languages;
    }

    public function run(string $language, string $code, string $stdin = ''): SandboxResult
    {
        $this->received[] = ['language' => $language, 'code' => $code, 'stdin' => $stdin];

        if (! in_array($language, $this->languages, true)) {
            return SandboxResult::failure("the sandbox does not run [{$language}].");
        }

        return array_shift($this->queue) ?? new SandboxResult(stdout: $code, exitCode: 0);
    }
}
