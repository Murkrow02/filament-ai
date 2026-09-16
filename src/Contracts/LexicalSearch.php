<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Murkrow\FilamentAi\Data\VectorQuery;

/**
 * Optional keyword leg of hybrid retrieval, fused with the vector leg via RRF.
 */
interface LexicalSearch
{
    /**
     * @return array<int, int> chunk ids in relevance order
     */
    public function candidates(string $query, VectorQuery $filters, int $limit): array;

    public function isAvailable(): bool;
}
