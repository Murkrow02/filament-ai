<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Embeddings;

use Laravel\Ai\Embeddings;
use Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration;
use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Data\EmbeddingBatch;
use Murkrow\FilamentAi\Exceptions\DimensionMismatchException;
use UnexpectedValueException;

/**
 * Embeddings through laravel/ai.
 *
 * The provider is a key of the host's `config/ai.php` `providers` array; null
 * falls back to `ai.default_for_embeddings`.
 *
 * laravel/ai's own embedding cache is left off: query embeddings are already
 * cached one layer up (`rag.embeddings.cache_queries`), and document vectors
 * are reused by content hash in `ChunkDiffer`, so a second cache would only
 * spend storage on vectors that are never asked for twice.
 */
final class LaravelAiEmbeddingProvider implements EmbeddingProvider
{
    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        private readonly ?string $provider,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $batchSize = 96,
        private readonly int $maxInputTokens = 8000,
        private readonly bool $normalize = true,
        private readonly string $documentPrefix = '',
        private readonly string $queryPrefix = '',
        private readonly array $providerOptions = [],
        private readonly ?int $timeout = null,
    ) {}

    public static function fromConfig(): self
    {
        $provider = config('filament-ai.embeddings.provider');
        $timeout = config('filament-ai.embeddings.timeout');

        return new self(
            provider: blank($provider) ? null : (string) $provider,
            model: (string) config('filament-ai.embeddings.model', 'text-embedding-3-small'),
            dimensions: (int) config('filament-ai.embeddings.dimensions', 1536),
            batchSize: (int) config('filament-ai.embeddings.batch_size', 96),
            maxInputTokens: (int) config('filament-ai.embeddings.max_input_tokens', 8000),
            normalize: (bool) config('filament-ai.embeddings.normalize', true),
            documentPrefix: (string) config('filament-ai.embeddings.document_prefix', ''),
            queryPrefix: (string) config('filament-ai.embeddings.query_prefix', ''),
            providerOptions: (array) config('filament-ai.embeddings.provider_options', []),
            timeout: blank($timeout) ? null : (int) $timeout,
        );
    }

    public function embedBatch(array $texts): EmbeddingBatch
    {
        if ($texts === []) {
            return new EmbeddingBatch([], $this->model, $this->dimensions);
        }

        $inputs = array_map(fn (string $text): string => $this->documentPrefix.$text, array_values($texts));

        $response = $this->request($inputs)->generate($this->provider, $this->model);

        // The vectors are matched back to their chunks by position, so a
        // short answer must fail here rather than shift every later vector
        // onto the wrong chunk.
        if (count($response->embeddings) !== count($inputs)) {
            throw new UnexpectedValueException(sprintf(
                'The embedding provider returned %d vectors for %d inputs.',
                count($response->embeddings),
                count($inputs),
            ));
        }

        return new EmbeddingBatch(
            vectors: array_map(fn (array $vector): array => $this->finalize($vector), array_values($response->embeddings)),
            model: $this->model,
            dimensions: $this->dimensions,
            tokens: $response->tokens,
        );
    }

    public function embedQuery(string $text): array
    {
        $input = $this->queryPrefix.$text;

        // laravel/ai refuses blank input outright; a blank question has
        // nothing to retrieve either way.
        if (trim($input) === '') {
            return [];
        }

        $first = $this->request([$input])->generate($this->provider, $this->model)->embeddings[0] ?? null;

        return $first === null ? [] : $this->finalize($first);
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function maxBatchSize(): int
    {
        return $this->batchSize;
    }

    public function maxInputTokens(): int
    {
        return $this->maxInputTokens;
    }

    /**
     * @param  list<string>  $inputs
     */
    private function request(array $inputs): PendingEmbeddingsGeneration
    {
        return Embeddings::for($inputs)
            ->dimensions($this->dimensions)
            ->withProviderOptions($this->providerOptions)
            ->when($this->timeout !== null, fn (PendingEmbeddingsGeneration $pending) => $pending->timeout($this->timeout));
    }

    /**
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    private function finalize(array $vector): array
    {
        $actual = count($vector);

        if ($actual !== $this->dimensions) {
            // Fail loudly rather than storing a corpus at two widths, which
            // pgvector would reject only on the second insert.
            throw DimensionMismatchException::make($this->dimensions, $actual);
        }

        return $this->normalize ? VectorMath::normalize($vector) : $vector;
    }
}
