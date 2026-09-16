<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

use Closure;

/**
 * Caller-facing retrieval knobs. Anything left null falls back to config,
 * which in turn may be overridden at runtime from the settings table.
 */
final readonly class RetrievalOptions
{
    /**
     * @param  array<int, string>|null  $sourceKeys
     * @param  array<int, int>|null  $documentIds
     * @param  array<int, string>|null  $externalIds
     * @param  (Closure(\Illuminate\Database\Eloquent\Builder): mixed)|null  $constrain
     */
    public function __construct(
        public ?array $sourceKeys = null,
        public ?array $documentIds = null,
        public ?array $externalIds = null,
        public ?int $positionFrom = null,
        public ?int $positionTo = null,
        public ?int $topK = null,
        public ?int $fetchK = null,
        public ?float $minScore = null,
        public ?bool $mmr = null,
        public ?float $mmrLambda = null,
        public ?int $expandNeighbors = null,
        public ?string $hybridDriver = null,
        public ?Closure $constrain = null,
        public bool $withVectors = false,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $toList = static function (mixed $value): ?array {
            if ($value === null || $value === '' || $value === []) {
                return null;
            }

            return array_values((array) $value);
        };

        return new self(
            sourceKeys: $toList($input['sources'] ?? $input['source'] ?? null),
            documentIds: $toList($input['document_ids'] ?? null),
            externalIds: $toList($input['external_ids'] ?? null),
            positionFrom: isset($input['position_from']) ? (int) $input['position_from'] : null,
            positionTo: isset($input['position_to']) ? (int) $input['position_to'] : null,
            topK: isset($input['top_k']) ? (int) $input['top_k'] : null,
            fetchK: isset($input['fetch_k']) ? (int) $input['fetch_k'] : null,
            minScore: isset($input['min_score']) ? (float) $input['min_score'] : null,
            mmr: isset($input['mmr']) ? (bool) $input['mmr'] : null,
            mmrLambda: isset($input['mmr_lambda']) ? (float) $input['mmr_lambda'] : null,
            expandNeighbors: isset($input['expand_neighbors']) ? (int) $input['expand_neighbors'] : null,
            hybridDriver: isset($input['hybrid']) ? (string) $input['hybrid'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'sources' => $this->sourceKeys,
            'document_ids' => $this->documentIds,
            'external_ids' => $this->externalIds,
            'position_from' => $this->positionFrom,
            'position_to' => $this->positionTo,
            'top_k' => $this->topK,
            'min_score' => $this->minScore,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
