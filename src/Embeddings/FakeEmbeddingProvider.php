<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Embeddings;

use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Data\EmbeddingBatch;

/**
 * Deterministic, network-free embeddings for tests and offline development.
 *
 * The vector is derived from a hash of the text, so identical text always maps
 * to an identical vector and similar-but-different text does not -- enough to
 * exercise the whole pipeline, storage and ranking included, without an API key.
 * It is emphatically not semantic: never point a real corpus at it.
 */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly int $dimensions = 1536,
        private readonly string $model = 'fake-embedding',
        private readonly int $batchSize = 96,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            dimensions: (int) config('rag.embeddings.dimensions', 1536),
            model: (string) config('rag.embeddings.model', 'fake-embedding'),
            batchSize: (int) config('rag.embeddings.batch_size', 96),
        );
    }

    /**
     * @param  array<int, string>  $texts
     */
    public function embedBatch(array $texts): EmbeddingBatch
    {
        $vectors = [];
        $tokens = 0;

        foreach (array_values($texts) as $text) {
            $vectors[] = $this->vectorFor($text);
            $tokens += (int) ceil(mb_strlen($text) / 4);
        }

        return new EmbeddingBatch($vectors, $this->model, $this->dimensions, $tokens);
    }

    /**
     * @return array<int, float>
     */
    public function embedQuery(string $text): array
    {
        return $this->vectorFor($text);
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
        return 100_000;
    }

    /**
     * Seed a PRNG from the text so the mapping is stable across processes,
     * then bias a handful of dimensions by word hashes so that texts sharing
     * vocabulary end up measurably closer than texts that do not.
     *
     * @return array<int, float>
     */
    private function vectorFor(string $text): array
    {
        $normalized = mb_strtolower(trim($text));

        $seed = (int) hexdec(substr(hash('sha256', $normalized), 0, 12));
        mt_srand($seed);

        $vector = [];

        for ($i = 0; $i < $this->dimensions; $i++) {
            $vector[$i] = (mt_rand() / mt_getrandmax()) * 2 - 1;
        }

        mt_srand();

        foreach (preg_split('/\W+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $slot = (int) hexdec(substr(hash('crc32b', (string) $word), 0, 6)) % $this->dimensions;
            $vector[$slot] += 1.0;
        }

        return VectorMath::normalize($vector);
    }
}
