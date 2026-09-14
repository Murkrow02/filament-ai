<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

use Closure;

/**
 * Everything a vector store needs to run one similarity search.
 *
 * Filters are expressed as plain data so any driver can compile them, with a
 * `constrain` closure as the escape hatch for arbitrary Eloquent constraints.
 */
final readonly class VectorQuery
{
    /**
     * @param  array<int, float>  $vector  already L2-normalised
     * @param  array<int, string>|null  $sourceKeys
     * @param  array<int, int>|null  $documentIds  rag_documents.id
     * @param  array<int, string>|null  $externalIds  host primary keys
     * @param  (Closure(\Illuminate\Database\Eloquent\Builder): mixed)|null  $constrain
     * @param  array<int, int>|null  $restrictToChunkIds
     */
    public function __construct(
        public array $vector,
        public int $limit = 8,
        public ?array $sourceKeys = null,
        public ?array $documentIds = null,
        public ?array $externalIds = null,
        public ?int $positionFrom = null,
        public ?int $positionTo = null,
        public ?float $minScore = null,
        public ?Closure $constrain = null,
        public ?array $restrictToChunkIds = null,
    ) {}

    public function withLimit(int $limit): self
    {
        return new self(
            $this->vector, $limit, $this->sourceKeys, $this->documentIds, $this->externalIds,
            $this->positionFrom, $this->positionTo, $this->minScore, $this->constrain,
            $this->restrictToChunkIds,
        );
    }

    /**
     * @param  array<int, int>|null  $chunkIds
     */
    public function restrictedTo(?array $chunkIds): self
    {
        return new self(
            $this->vector, $this->limit, $this->sourceKeys, $this->documentIds, $this->externalIds,
            $this->positionFrom, $this->positionTo, $this->minScore, $this->constrain, $chunkIds,
        );
    }
}
