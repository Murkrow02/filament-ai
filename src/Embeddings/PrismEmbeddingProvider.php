<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Embeddings;

use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Data\EmbeddingBatch;
use Murkrow\FilamentAi\Exceptions\DimensionMismatchException;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

/**
 * Embeddings through Prism, which is what makes the provider choice a config
 * value rather than a code change: OpenAI, Ollama, VoyageAI, Bedrock and the
 * rest all go through the same call.
 *
 * Vectors are L2-normalised here, once, so every consumer downstream can treat
 * cosine similarity and the dot product as the same thing.
 */
final class PrismEmbeddingProvider implements EmbeddingProvider
{
    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $batchSize = 96,
        private readonly int $maxInputTokens = 8000,
        private readonly bool $normalize = true,
        private readonly string $documentPrefix = '',
        private readonly string $queryPrefix = '',
        private readonly array $providerOptions = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            provider: (string) config('rag.embeddings.prism_provider', 'openai'),
            model: (string) config('rag.embeddings.model', 'text-embedding-3-small'),
            dimensions: (int) config('rag.embeddings.dimensions', 1536),
            batchSize: (int) config('rag.embeddings.batch_size', 96),
            maxInputTokens: (int) config('rag.embeddings.max_input_tokens', 8000),
            normalize: (bool) config('rag.embeddings.normalize', true),
            documentPrefix: (string) config('rag.embeddings.document_prefix', ''),
            queryPrefix: (string) config('rag.embeddings.query_prefix', ''),
            providerOptions: (array) config('rag.embeddings.provider_options', []),
        );
    }

    /**
     * @param  array<int, string>  $texts
     */
    public function embedBatch(array $texts): EmbeddingBatch
    {
        if ($texts === []) {
            return new EmbeddingBatch([], $this->model, $this->dimensions);
        }

        $inputs = array_map(fn (string $text): string => $this->documentPrefix.$text, array_values($texts));

        $response = $this->request()->fromArray($inputs)->asEmbeddings();

        $vectors = [];

        foreach ($response->embeddings as $embedding) {
            $vectors[] = $this->finalize($embedding->embedding);
        }

        return new EmbeddingBatch(
            vectors: $vectors,
            model: $this->model,
            dimensions: $this->dimensions,
            tokens: (int) ($response->usage->tokens ?? 0),
        );
    }

    /**
     * @return array<int, float>
     */
    public function embedQuery(string $text): array
    {
        $response = $this->request()->fromInput($this->queryPrefix.$text)->asEmbeddings();

        $first = $response->embeddings[0] ?? null;

        if ($first === null) {
            return [];
        }

        return $this->finalize($first->embedding);
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

    private function request(): mixed
    {
        $provider = Provider::tryFrom($this->provider) ?? $this->provider;

        $request = Prism::embeddings()->using($provider, $this->model);

        if ($this->providerOptions !== [] && method_exists($request, 'withProviderOptions')) {
            $request = $request->withProviderOptions($this->providerOptions);
        }

        return $request;
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
