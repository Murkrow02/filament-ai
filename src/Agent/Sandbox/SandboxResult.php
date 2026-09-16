<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Sandbox;

use Illuminate\Support\Str;

/**
 * What came back from one run: the two streams, how it ended, and how long it
 * took.
 *
 * `failed` is about the sandbox itself (unreachable, refused, misconfigured),
 * not about the snippet: a program that throws ran perfectly well and its
 * stack trace is the answer the model needs to fix it.
 */
final readonly class SandboxResult
{
    public function __construct(
        public string $stdout = '',
        public string $stderr = '',
        public ?int $exitCode = null,
        public ?string $signal = null,
        public bool $timedOut = false,
        public int $durationMs = 0,
        public bool $failed = false,
        public ?string $error = null,
    ) {}

    public static function failure(string $error): self
    {
        return new self(failed: true, error: $error);
    }

    public function successful(): bool
    {
        return ! $this->failed && ! $this->timedOut && ($this->exitCode === null || $this->exitCode === 0);
    }

    /**
     * The single string the tool hands back to the model.
     *
     * Both streams are reported, truncated from the end: an exception's last
     * lines say more than its first.
     */
    public function toToolOutput(int $maxCharacters): string
    {
        if ($this->failed) {
            return 'Error: '.($this->error ?? 'the sandbox could not run this.');
        }

        $parts = [];

        if ($this->timedOut) {
            $parts[] = '[timed out]';
        }

        if (trim($this->stdout) !== '') {
            $parts[] = "stdout:\n".$this->clamp($this->stdout, $maxCharacters);
        }

        if (trim($this->stderr) !== '') {
            $parts[] = "stderr:\n".$this->clamp($this->stderr, $maxCharacters);
        }

        if ($this->exitCode !== null && $this->exitCode !== 0) {
            $parts[] = "exit code: {$this->exitCode}";
        }

        if ($this->signal !== null) {
            $parts[] = "killed by: {$this->signal}";
        }

        return $parts === []
            ? 'The program ran and printed nothing. Print what you want to see.'
            : implode("\n\n", $parts);
    }

    private function clamp(string $text, int $maxCharacters): string
    {
        $text = rtrim($text);

        if (mb_strlen($text) <= $maxCharacters) {
            return $text;
        }

        return "[... truncated ...]\n".Str::substr($text, -$maxCharacters);
    }
}
