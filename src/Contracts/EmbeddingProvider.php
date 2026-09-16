<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Murkrow\FilamentAi\Data\EmbeddingBatch;

interface EmbeddingProvider
{
    /**
     * Embed several documents in a single API call.
     *
     * @param  array<int, string>  $texts
     */
    public function embedBatch(array $texts): EmbeddingBatch;

    /**
     * Embed a single search query. Kept separate from embedBatch() because
     * asymmetric models (e5, bge) require a different prefix for queries.
     *
     * @return array<int, float>
     */
    public function embedQuery(string $text): array;

    public function model(): string;

    public function dimensions(): int;

    public function maxBatchSize(): int;

    public function maxInputTokens(): int;
}
