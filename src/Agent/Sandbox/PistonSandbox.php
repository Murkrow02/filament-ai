<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Sandbox;

use Illuminate\Support\Facades\Http;
use Murkrow\FilamentAi\Contracts\CodeSandbox;
use Murkrow\FilamentAi\Contracts\ListsRuntimes;
use Throwable;

/**
 * A self-hosted Piston (https://github.com/engineer-man/piston).
 *
 * Piston runs each submission under isolate, with outgoing network disabled
 * and its own process, file and memory limits, which is why the package does
 * not try to sandbox anything itself: this class only speaks HTTP to it and
 * translates the answer.
 *
 * The version for each language is pinned in config. Piston accepts a SemVer
 * selector, and `*` means "whatever is installed" -- convenient in
 * development, worth pinning anywhere the answers should stay reproducible.
 */
final class PistonSandbox implements CodeSandbox, ListsRuntimes
{
    /**
     * @param  array<string, string>  $languages  language name => version selector
     */
    public function __construct(
        private readonly string $url,
        private readonly array $languages,
        private readonly int $runTimeoutMs = 5000,
        private readonly int $memoryLimitBytes = 134217728,
        private readonly int $httpTimeoutSeconds = 15,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            url: rtrim((string) config('filament-ai.agent.sandbox.url', 'http://piston:2000'), '/'),
            languages: (array) config('filament-ai.agent.sandbox.languages', ['python' => '*']),
            runTimeoutMs: (int) config('filament-ai.agent.sandbox.timeout', 5000),
            memoryLimitBytes: (int) config('filament-ai.agent.sandbox.memory_limit', 134217728),
            httpTimeoutSeconds: (int) config('filament-ai.agent.sandbox.http_timeout', 15),
        );
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        return array_values(array_keys($this->languages));
    }

    /**
     * What Piston has installed right now, newest version per language.
     *
     * Answers an empty array when the sandbox cannot be reached: the settings
     * page treats that as "nothing to choose from" and says so, rather than
     * letting an administrator pick a language that is not there.
     *
     * @return array<string, string>
     */
    public function runtimes(): array
    {
        try {
            $response = Http::timeout($this->httpTimeoutSeconds)->acceptJson()->get($this->url.'/api/v2/runtimes');
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $runtimes = [];

        foreach ((array) $response->json() as $runtime) {
            if (! is_array($runtime) || ! isset($runtime['language'], $runtime['version'])) {
                continue;
            }

            $language = (string) $runtime['language'];
            $version = (string) $runtime['version'];

            if (! isset($runtimes[$language]) || version_compare($version, $runtimes[$language], '>')) {
                $runtimes[$language] = $version;
            }
        }

        ksort($runtimes);

        return $runtimes;
    }

    public function run(string $language, string $code, string $stdin = ''): SandboxResult
    {
        if (! array_key_exists($language, $this->languages)) {
            return SandboxResult::failure("the sandbox does not run [{$language}].");
        }

        $startedAt = hrtime(true);

        try {
            $response = Http::timeout($this->httpTimeoutSeconds)
                ->acceptJson()
                ->post($this->url.'/api/v2/execute', [
                    'language' => $language,
                    'version' => $this->languages[$language],
                    'files' => [['content' => $code]],
                    'stdin' => $stdin,
                    'run_timeout' => $this->runTimeoutMs,
                    'run_memory_limit' => $this->memoryLimitBytes,
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return SandboxResult::failure('the sandbox is unreachable.');
        }

        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if ($response->failed()) {
            // Piston answers 400 with a message for an unknown runtime or a
            // malformed request; anything else is the sandbox being unwell.
            $message = (string) ($response->json('message') ?? 'the sandbox refused the request.');

            return SandboxResult::failure($message);
        }

        $run = (array) ($response->json('run') ?? []);

        return new SandboxResult(
            stdout: (string) ($run['stdout'] ?? ''),
            stderr: (string) ($run['stderr'] ?? ''),
            exitCode: isset($run['code']) ? (int) $run['code'] : null,
            signal: isset($run['signal']) ? (string) $run['signal'] : null,
            // Piston reports a wall-clock kill as status TO, and a CPU kill as
            // a SIGKILL signal; both are "it did not finish in time".
            timedOut: ($run['status'] ?? null) === 'TO' || ($run['signal'] ?? null) === 'SIGKILL',
            durationMs: $durationMs,
        );
    }
}
