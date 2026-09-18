<?php

declare(strict_types=1);

use Laravel\Ai\Embeddings;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Embeddings\EmbeddingManager;
use Murkrow\FilamentAi\Embeddings\LaravelAiEmbeddingProvider;
use Murkrow\FilamentAi\Exceptions\DimensionMismatchException;
use Murkrow\FilamentAi\Llm\LanguageModelManager;
use Murkrow\FilamentAi\Llm\LaravelAi\CompletionAgent;
use Murkrow\FilamentAi\Llm\LaravelAiLanguageModel;

function laravelAiModel(?float $temperature = 0.1): LaravelAiLanguageModel
{
    return new LaravelAiLanguageModel(provider: null, model: 'gpt-4o-mini', temperature: $temperature, maxTokens: 300);
}

function laravelAiEmbedder(int $dimensions = 8): LaravelAiEmbeddingProvider
{
    return new LaravelAiEmbeddingProvider(provider: null, model: 'text-embedding-3-small', dimensions: $dimensions);
}

it('resolves the laravel-ai drivers by name', function (): void {
    config()->set('filament-ai.llm.driver', 'laravel-ai');
    config()->set('filament-ai.embeddings.driver', 'laravel-ai');

    expect(app(LanguageModelManager::class)->driver())->toBeInstanceOf(LaravelAiLanguageModel::class)
        ->and(app(EmbeddingManager::class)->driver())->toBeInstanceOf(LaravelAiEmbeddingProvider::class);
});

it('generates a completion and reports the model it used', function (): void {
    CompletionAgent::fake(['Il podesta convoco il consiglio. [#1]']);

    $result = laravelAiModel()->generate('Answer from the sources.', 'Who convened the council?');

    expect($result['text'])->toBe('Il podesta convoco il consiglio. [#1]')
        ->and($result['model'])->toBe('gpt-4o-mini');

    CompletionAgent::assertPrompted('Who convened the council?');
});

it('streams deltas that add up to the whole answer', function (): void {
    CompletionAgent::fake(['Il podesta convoco il consiglio. [#1]']);

    $stream = laravelAiModel()->stream('Answer from the sources.', 'Who convened the council?');

    $deltas = iterator_to_array($stream, false);

    // Reading only one event shape once yielded zero deltas and turned every
    // streamed answer into a refusal; the joined deltas must be the answer.
    expect($deltas)->not->toBeEmpty()
        ->and(implode('', $deltas))->toBe('Il podesta convoco il consiglio. [#1]')
        ->and($stream->getReturn()['text'])->toBe('Il podesta convoco il consiglio. [#1]');
});

it('leaves a null temperature out of the request', function (): void {
    $options = TextGenerationOptions::forAgent(new CompletionAgent('system', temperatureValue: null, maxTokensValue: 300));

    expect($options->temperature)->toBeNull()
        ->and($options->maxTokens)->toBe(300);
});

it('embeds a batch into one normalised vector per input', function (): void {
    Embeddings::fake();

    $batch = laravelAiEmbedder()->embedBatch(['prima pagina', 'seconda pagina']);

    expect($batch->vectors)->toHaveCount(2)
        ->and($batch->vectors[0])->toHaveCount(8)
        ->and(array_sum(array_map(fn (float $v): float => $v * $v, $batch->vectors[0])))->toEqualWithDelta(1.0, 1e-6);
});

it('never sends a blank query to the provider', function (): void {
    Embeddings::fake();

    expect(laravelAiEmbedder()->embedQuery('   '))->toBe([]);

    Embeddings::assertNothingGenerated();
});

it('refuses a vector of the wrong width', function (): void {
    Embeddings::fake([[[0.1, 0.2, 0.3]]]);

    laravelAiEmbedder(dimensions: 8)->embedQuery('una domanda');
})->throws(DimensionMismatchException::class);

it('keeps both drivers behind the package contracts', function (): void {
    expect(laravelAiModel())->toBeInstanceOf(LanguageModel::class)
        ->and(laravelAiEmbedder())->toBeInstanceOf(EmbeddingProvider::class);
});
