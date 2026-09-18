<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Sandbox\SandboxResult;
use Murkrow\FilamentAi\Contracts\CodeSandbox;

/**
 * Lets the agent write a program and run it, instead of the host writing a
 * tool for every calculation.
 *
 * This is the most dangerous thing the package can hand a model, so it is off
 * unless a host switches it on, and the isolation is not this class's to
 * provide: the `CodeSandbox` behind it is the boundary. What happens here is
 * the part a sandbox cannot do -- refusing a language nobody allowed, capping
 * the size of what goes in and comes out, and writing down every snippet that
 * ran, because "the agent computed it" is not an audit trail.
 *
 * The sandbox reaches nothing of the application: no database, no filesystem,
 * no network. Data the program needs is data the agent read with another tool
 * and passed in.
 */
final class RunCode implements Tool
{
    public function name(): string
    {
        return 'run_code';
    }

    public function description(): string
    {
        $languages = implode(', ', $this->languages());

        return "Write a short program and run it in a sandbox ({$languages}), then read its output. "
            .'Use it for anything mechanical: anagrams and permutations, ciphers and encodings, parsing, arithmetic over many values, dates. '
            .'The sandbox has no network and no access to this application: pass any data the program needs in the code itself or through stdin. '
            .'Print results explicitly -- only what the program writes comes back.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()
                ->enum($this->languages())
                ->description('The language to run.')
                ->required(),
            'code' => $schema->string()
                ->description('The complete program. It gets no arguments; print what you want to see.')
                ->required(),
            'stdin' => $schema->string()
                ->description('Optional text the program reads from standard input.'),
        ];
    }

    public function handle(Request $request): string
    {
        if (! self::enabled()) {
            return 'Error: running code is switched off for this application.';
        }

        $arguments = $request->all();
        $code = (string) ($arguments['code'] ?? '');
        $language = (string) ($arguments['language'] ?? (self::languagesFromConfig()[0] ?? 'python'));

        if (trim($code) === '') {
            return 'Error: the "code" argument is required.';
        }

        $maxCode = (int) config('filament-ai.agent.sandbox.max_code_characters', 20000);

        if (mb_strlen($code) > $maxCode) {
            return "Error: the program is longer than {$maxCode} characters. Send something smaller.";
        }

        $sandbox = app(CodeSandbox::class);
        $result = $sandbox->run($language, $code, (string) ($arguments['stdin'] ?? ''));

        $this->record($language, $code, $result);

        return $result->toToolOutput((int) config('filament-ai.agent.sandbox.max_output', 4000));
    }

    public static function enabled(): bool
    {
        return (bool) config('filament-ai.enabled', true)
            && (bool) config('filament-ai.agent.enabled', true)
            && (bool) config('filament-ai.agent.sandbox.enabled', false);
    }

    /**
     * @return list<string>
     */
    private function languages(): array
    {
        $languages = app(CodeSandbox::class)->languages();

        return $languages === [] ? self::languagesFromConfig() : $languages;
    }

    /**
     * @return list<string>
     */
    private static function languagesFromConfig(): array
    {
        return array_values(array_keys((array) config('filament-ai.agent.sandbox.languages', ['python' => '*'])));
    }

    private function record(string $language, string $code, SandboxResult $result): void
    {
        $channel = config('filament-ai.agent.sandbox.log_channel');

        $logger = $channel === null || $channel === '' ? Log::getFacadeRoot() : Log::channel((string) $channel);

        $logger->info('filament-ai: sandbox run', [
            'user' => auth()->id(),
            'language' => $language,
            'code' => Str::limit($code, 2000),
            'exit_code' => $result->exitCode,
            'timed_out' => $result->timedOut,
            'failed' => $result->failed,
            'duration_ms' => $result->durationMs,
        ]);
    }
}
