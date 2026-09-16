<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Llm;

use Generator;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Data\Usage;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

/**
 * Text generation through Prism.
 *
 * Kept behind the LanguageModel contract -- rather than calling Prism from the
 * answerer -- so tests can run the whole RAG loop against FakeLanguageModel
 * with no API key, and so a host can substitute its own client.
 */
final class PrismLanguageModel implements LanguageModel
{
    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        // Null skips ->usingTemperature() entirely. Some models (Claude's
        // Fable/Opus/Sonnet 5 tier) reject the parameter outright with a 400
        // rather than clamping or ignoring it.
        private readonly ?float $temperature = 0.1,
        private readonly int $maxTokens = 1200,
        private readonly array $providerOptions = [],
    ) {}

    public static function fromConfig(): self
    {
        $temperature = config('rag.llm.temperature', 0.1);

        return new self(
            provider: (string) config('rag.llm.prism_provider', 'openai'),
            model: (string) config('rag.llm.model', 'gpt-4o-mini'),
            temperature: $temperature === null || $temperature === '' ? null : (float) $temperature,
            maxTokens: (int) config('rag.llm.max_tokens', 1200),
            providerOptions: (array) config('rag.llm.provider_options', []),
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

        $response = $this->request($systemPrompt, $userPrompt, $model, $temperature, $maxTokens)->asText();

        $promptTokens = (int) ($response->usage->promptTokens ?? 0);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);

        return [
            'text' => (string) $response->text,
            'usage' => new Usage(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                costMicros: CostCalculator::completionMicros($model, $promptTokens, $completionTokens),
            ),
            'model' => $model,
        ];
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

        foreach ($this->request($systemPrompt, $userPrompt, $model, $temperature, $maxTokens)->asStream() as $event) {
            $delta = $this->deltaOf($event);

            if ($delta !== '') {
                $text .= $delta;
                yield $delta;
            }

            // Usage arrives on the terminal event only.
            if (isset($event->usage)) {
                $promptTokens = (int) ($event->usage->promptTokens ?? $promptTokens);
                $completionTokens = (int) ($event->usage->completionTokens ?? $completionTokens);
            }
        }

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

    /**
     * The text carried by one streamed event, whatever Prism calls it today.
     *
     * Prism streams typed events (`TextDeltaEvent`, `StreamEndEvent`, ...) and
     * puts the text on `$event->delta`; older releases yielded plain chunks
     * with `$chunk->text`. Reading only one of the two is how this silently
     * produced an empty answer: every delta was skipped, the accumulated text
     * came out empty, and an empty answer cites nothing -- so the answerer
     * reported a perfectly good retrieval as a refusal.
     */
    private function deltaOf(object $event): string
    {
        foreach (['delta', 'text'] as $property) {
            if (property_exists($event, $property)) {
                $value = $event->{$property};

                if (is_string($value)) {
                    return $value;
                }
            }
        }

        return '';
    }

    public function model(): string
    {
        return $this->model;
    }

    private function request(
        string $systemPrompt,
        string $userPrompt,
        string $model,
        ?float $temperature,
        ?int $maxTokens,
    ): mixed {
        $provider = Provider::tryFrom($this->provider) ?? $this->provider;

        $request = Prism::text()
            ->using($provider, $model)
            ->withSystemPrompt($systemPrompt)
            ->withPrompt($userPrompt)
            ->withMaxTokens($maxTokens ?? $this->maxTokens);

        $temperature ??= $this->temperature;

        if ($temperature !== null) {
            $request = $request->usingTemperature($temperature);
        }

        if ($this->providerOptions !== [] && method_exists($request, 'withProviderOptions')) {
            $request = $request->withProviderOptions($this->providerOptions);
        }

        return $request;
    }
}
