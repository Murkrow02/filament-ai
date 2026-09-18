<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Ingestion\CostCalculator;

/*
 * Providers answer with dated model ids while price lists carry the plain
 * name. Matching only exactly priced almost every real answer at zero: the
 * tokens were counted and the money was not, which is worse than no accounting
 * at all because the zero looks like an answer.
 */

beforeEach(function (): void {
    config()->set('filament-ai.llm.pricing', [
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
    ]);

    config()->set('filament-ai.embeddings.pricing', ['text-embedding-3-small' => 0.02]);
});

it('prices a model named exactly as the list', function (): void {
    expect(CostCalculator::completionMicros('claude-haiku-4-5', 1_000_000, 0))->toBe(1_000_000);
});

it('prices a dated snapshot of a known model', function (): void {
    expect(CostCalculator::completionMicros('claude-haiku-4-5-20251001', 1_000_000, 0))->toBe(1_000_000)
        ->and(CostCalculator::completionMicros('gpt-4o-mini-2024-07-18', 0, 1_000_000))->toBe(600_000);
});

it('never prices a smaller model as its bigger namesake', function (): void {
    // "gpt-4o" is a prefix of "gpt-4o-mini": longest match wins, or a cheap
    // model would be billed as the expensive one.
    expect(CostCalculator::priceKeyFor('filament-ai.llm.pricing', 'gpt-4o-mini-2024-07-18'))->toBe('gpt-4o-mini')
        ->and(CostCalculator::priceKeyFor('filament-ai.llm.pricing', 'gpt-4o-2024-11-20'))->toBe('gpt-4o');
});

it('charges nothing for a model nobody priced', function (): void {
    expect(CostCalculator::completionMicros('llama-3.1-70b', 1_000_000, 1_000_000))->toBe(0)
        ->and(CostCalculator::priceKeyFor('filament-ai.llm.pricing', 'llama-3.1-70b'))->toBeNull();
});

it('prices embeddings the same way', function (): void {
    expect(CostCalculator::embeddingMicros('text-embedding-3-small-20240101', 1_000_000))->toBe(20_000);
});
