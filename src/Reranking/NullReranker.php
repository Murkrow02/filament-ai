<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Reranking;

use Murkrow\FilamentAi\Contracts\Reranker;

/**
 * The default: no second stage, the fused order stands.
 */
final class NullReranker implements Reranker
{
    public function score(string $question, array $passages): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
