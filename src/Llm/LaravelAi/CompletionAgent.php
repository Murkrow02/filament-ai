<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Llm\LaravelAi;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * A single-turn, tool-less agent: one system prompt in, one completion out.
 *
 * laravel/ai only speaks in agents, and reads generation settings either from
 * class attributes or from same-named methods. Attributes are fixed at compile
 * time and these values come from config, so they are exposed as methods —
 * `TextGenerationOptions::forAgent()` prefers a method's non-null return over
 * an attribute. A null temperature is left null on purpose: some models reject
 * the parameter outright instead of clamping it.
 *
 * Internal to the language-model driver. Tests fake it with
 * `CompletionAgent::fake([...])`.
 */
final class CompletionAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        private readonly string $systemPrompt,
        private readonly ?float $temperatureValue = null,
        private readonly ?int $maxTokensValue = null,
        private readonly array $providerOptions = [],
    ) {}

    public function instructions(): string
    {
        return $this->systemPrompt;
    }

    public function temperature(): ?float
    {
        return $this->temperatureValue;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokensValue;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions;
    }
}
