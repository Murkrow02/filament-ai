<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval\Lexical;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Contracts\LexicalSearch;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Postgres full-text search over the chunk table.
 *
 * Complements the vector leg on the cases embeddings are weakest at: exact
 * names, dates, catalogue numbers and rare proper nouns, where lexical match is
 * precisely what the user meant.
 *
 * Requires the GIN index created by `rag:install --fulltext`; without it this
 * still returns correct results, just with a sequential scan.
 */
final class TsVectorLexicalSearch implements LexicalSearch
{
    public function isAvailable(): bool
    {
        return $this->connection()->getDriverName() === 'pgsql';
    }

    /**
     * @return array<int, int>
     */
    public function candidates(string $query, VectorQuery $filters, int $limit): array
    {
        if (trim($query) === '' || ! $this->isAvailable()) {
            return [];
        }

        $language = (string) config('rag.retrieval.hybrid.tsvector_language', 'simple');
        $chunks = Tables::chunks();

        $builder = $this->connection()->table($chunks)
            ->select('id')
            ->whereNotNull('embedded_at')
            ->whereRaw(
                "to_tsvector(?::regconfig, content) @@ websearch_to_tsquery(?::regconfig, ?)",
                [$language, $language, $query],
            )
            ->orderByRaw(
                'ts_rank(to_tsvector(?::regconfig, content), websearch_to_tsquery(?::regconfig, ?)) DESC',
                [$language, $language, $query],
            )
            ->limit($limit);

        if ($filters->sourceKeys !== null) {
            $builder->whereIn('source_key', $filters->sourceKeys);
        }

        if ($filters->documentIds !== null) {
            $builder->whereIn('document_id', $filters->documentIds);
        }

        if ($filters->positionFrom !== null) {
            $builder->where('position_end', '>=', $filters->positionFrom);
        }

        if ($filters->positionTo !== null) {
            $builder->where('position_start', '<=', $filters->positionTo);
        }

        return array_map(intval(...), $builder->pluck('id')->all());
    }

    private function connection(): Connection
    {
        /** @var Connection */
        return DB::connection(Tables::connection());
    }
}
