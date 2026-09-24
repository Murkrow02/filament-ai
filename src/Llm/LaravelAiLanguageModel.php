<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Llm;

use Generator;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Data\Usage;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Llm\LaravelAi\CompletionAgent;

/**
 * Generation through laravel/ai.
 *
 * The provider is a key of the host's `config/ai.php` `providers` array, so
 * credentials, base URLs and failover live where every other laravel/ai call
 * in the application already reads them. Null falls back to `ai.default`.
 *
 * laravel/ai is still 0.x: nothing outside this class and `CompletionAgent`
 * may depend on its request or event shapes.
 */
final class LaravelAiLanguageModel implements LanguageModel
{
    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        private readonly ?string $provider,
        private readonly string $model,
        // Null leaves the parameter out of the request. Some models (Claude's
        // Fable/Opus/Sonnet 5 tier) reject it with a 400 instead of clamping.
        private readonly ?float $temperature = 0.1,
        private readonly int $maxTokens = 1200,
        private readonly array $providerOptions = [],
        private readonly ?int $timeout = null,
    ) {}

    public static function fromConfig(): self
    {
        $temperature = config('filament-ai.llm.temperature', 0.1);
        $provider = config('filament-ai.llm.provider');
        $timeout = config('filament-ai.llm.timeout');

        return new self(
            provider: blank($provider) ? null : (string) $provider,
            model: (string) config('filament-ai.llm.model', 'gpt-4o-mini'),
            temperature: $temperature === null || $temperature === '' ? null : (float) $temperature,
            maxTokens: (int) config('filament-ai.llm.max_tokens', 1200),
            providerOptions: (array) config('filament-ai.llm.provider_options', []),
            timeout: blank($timeout) ? null : (int) $timeout,
        );
    }

    /**
     * @return array{text: string, usage: Usage, model: string}
     */
    public function generate(
        string $systemPrompt,
        string $userPrompt,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
    ): array {
        $model ??= $this->model;

        $response = $this->agent($systemPrompt, $temperature, $maxTokens)->prompt(
            $userPrompt,
            provider: $this->provider,
            model: $model,
            timeout: $this->timeout,
        );

        return $this->result(
            $response->text,
            $response->usage->inputTokens,
            $response->usage->outputTokens,
            $model,
        );
    }

    /**
     * @return Generator<int, string, mixed, array{text: string, usage: Usage, model: string}>
     */
    public function stream(
        string $systemPrompt,
        string $userPrompt,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
    ): Generator {
        $model ??= $this->model;
        $text = '';
        $promptTokens = 0;
        $completionTokens = 0;

        $events = $this->agent($systemPrompt, $temperature, $maxTokens)->stream(
            $userPrompt,
            provider: $this->provider,
            model: $model,
            timeout: $this->timeout,
        );

        foreach ($events as $event) {
            if ($event instanceof TextDelta && $event->delta !== '') {
                $text .= $event->delta;

                yield $event->delta;
            }

            // One StreamEnd per step. A tool-less agent takes one step, but
            // summing keeps the accounting right if that ever changes.
            if ($event instanceof StreamEnd) {
                $promptTokens += $event->usage->inputTokens;
                $completionTokens += $event->usage->outputTokens;
            }
        }

        return $this->result($text, $promptTokens, $completionTokens, $model);
    }

    public function model(): string
    {
        return $this->model;
    }

    private function agent(string $systemPrompt, ?float $temperature, ?int $maxTokens): CompletionAgent
    {
        return new CompletionAgent(
            systemPrompt: $systemPrompt,
            temperatureValue: $temperature ?? $this->temperature,
            maxTokensValue: $maxTokens ?? $this->maxTokens,
            providerOptions: $this->providerOptions,
        );
    }

    /**
     * @return array{text: string, usage: Usage, model: string}
     */
    private function result(string $text, int $promptTokens, int $completionTokens, string $model): array
    {
        return [
            'text' => $text,
            'usage' => new Usage(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                costMicros: CostCalculator::completionMicros($model, $promptTokens, $completionTokens),
            ),
            'model' => $model,
        ];
    }
}
