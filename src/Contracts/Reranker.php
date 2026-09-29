<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

/**
 * Optional second-stage ranking: reads each (question, passage) pair together
 * instead of comparing two independently computed vectors.
 */
interface Reranker
{
    /**
     * @param  list<string>  $passages
     * @return list<float> relevance in 0-1, in the order of `$passages`
     *
     * @throws \Throwable when the backend cannot score; the retriever then keeps its own order
     */
    public function score(string $question, array $passages): array;

    public function isAvailable(): bool;
}
