<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Generator;
use Murkrow\FilamentAi\Data\Usage;

interface LanguageModel
{
    /**
     * @return array{text: string, usage: Usage, model: string}
     */
    public function generate(string $systemPrompt, string $userPrompt, ?string $model = null, ?float $temperature = null, ?int $maxTokens = null): array;

    /**
     * Yields text deltas. The generator's return value is the same array shape
     * as generate(), available through Generator::getReturn().
     *
     * @return Generator<int, string, mixed, array{text: string, usage: Usage, model: string}>
     */
    public function stream(string $systemPrompt, string $userPrompt, ?string $model = null, ?float $temperature = null, ?int $maxTokens = null): Generator;

    public function model(): string;
}
