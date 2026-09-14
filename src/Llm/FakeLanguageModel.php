<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Llm;

use Generator;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Data\Usage;

/**
 * Offline stand-in for the generation model.
 *
 * By default it echoes back the first citation marker it can find in the
 * prompt, which is exactly what the citation parser and the "did the model
 * ground its answer" assertions need, without a network call. Tests that care
 * about specific wording can queue responses with `respondWith()`.
 */
final class FakeLanguageModel implements LanguageModel
{
    /** @var array<int, string> */
    private array $queue = [];

    /** @var array<int, array{system: string, user: string}> */
    private array $received = [];

    public function __construct(
        private readonly string $model = 'fake-llm',
    ) {}

    public function respondWith(string ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    /**
     * @return array<int, array{system: string, user: string}>
     */
    public function received(): array
    {
        return $this->received;
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
        $this->received[] = ['system' => $systemPrompt, 'user' => $userPrompt];

        $text = array_shift($this->queue) ?? $this->defaultAnswer($userPrompt);

        return [
            'text' => $text,
            'usage' => new Usage(
                promptTokens: (int) ceil(mb_strlen($systemPrompt.$userPrompt) / 4),
                completionTokens: (int) ceil(mb_strlen($text) / 4),
            ),
            'model' => $model ?? $this->model,
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
        $result = $this->generate($systemPrompt, $userPrompt, $model, $temperature, $maxTokens);

        foreach (str_split($result['text'], 16) as $delta) {
            yield $delta;
        }

        return $result;
    }

    public function model(): string
    {
        return $this->model;
    }

    private function defaultAnswer(string $userPrompt): string
    {
        preg_match('/\[#(\d+)\]/', $userPrompt, $matches);

        $marker = $matches[1] ?? null;

        return $marker === null
            ? 'No grounded answer available.'
            : "This is a fake grounded answer. [#{$marker}]";
    }
}
