<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval\Lexical;

use Laravel\Scout\Searchable;
use Murkrow\FilamentAi\Contracts\LexicalSearch;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Models\Chunk;

/**
 * Lexical leg backed by whichever engine Scout is configured with.
 *
 * Worth choosing over the Postgres one when the host already runs Meilisearch
 * or Typesense: typo tolerance and stemming come for free, and the index is
 * already part of their operational routine.
 *
 * Requires the host to make the chunk model searchable, which the package does
 * not do implicitly -- indexing millions of chunks is not a side effect anyone
 * should get by accident. See the README's hybrid retrieval section.
 */
final class ScoutLexicalSearch implements LexicalSearch
{
    public function isAvailable(): bool
    {
        return trait_exists(Searchable::class)
            && in_array(Searchable::class, class_uses_recursive(Chunk::class), true);
    }

    /**
     * @return array<int, int>
     */
    public function candidates(string $query, VectorQuery $filters, int $limit): array
    {
        if (trim($query) === '' || ! $this->isAvailable()) {
            return [];
        }

        /** @var \Laravel\Scout\Builder $builder */
        $builder = Chunk::search($query);

        if ($filters->sourceKeys !== null && count($filters->sourceKeys) === 1) {
            $builder->where('source_key', $filters->sourceKeys[0]);
        }

        if ($filters->documentIds !== null && $filters->documentIds !== []) {
            $builder->whereIn('document_id', $filters->documentIds);
        }

        return array_map(intval(...), $builder->take($limit)->keys()->all());
    }
}
