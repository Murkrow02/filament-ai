<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Exceptions;

class DimensionMismatchException extends RagException
{
    public static function make(int $expected, int $actual): self
    {
        return new self(
            "Embedding dimension mismatch: the vector store is configured for {$expected} dimensions "
            ."but the provider returned {$actual}. Align rag.embeddings.dimensions with the model, then "
            .'run `php artisan rag:vector:reindex` and re-embed.'
        );
    }
}
