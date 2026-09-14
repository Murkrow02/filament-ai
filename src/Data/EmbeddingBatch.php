<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * The result of one embedding API call.
 */
final readonly class EmbeddingBatch
{
    /**
     * @param  array<int, array<int, float>>  $vectors  in the same order as the inputs
     */
    public function __construct(
        public array $vectors,
        public string $model,
        public int $dimensions,
        public int $tokens = 0,
    ) {}

    public function count(): int
    {
        return count($this->vectors);
    }
}
