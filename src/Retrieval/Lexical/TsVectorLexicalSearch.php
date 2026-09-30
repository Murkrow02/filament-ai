<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval\Lexical;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Contracts\LexicalSearch;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Support\FullText;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Postgres full-text search over the chunk table.
 *
 * Complements the vector leg on the cases embeddings are weakest at: exact
 * names, dates, catalogue numbers and rare proper nouns, where lexical match is
 * precisely what the user meant.
 *
 * Every query word is its own OR'ed term, weighted by how rare it is (the IDF
 * half of BM25): a chunk with "beccaio" and "trippa" outranks one with
 * "Giovanni" and "casa", and a chunk missing one word of the question is still
 * found. `websearch_to_tsquery` would AND the words, and `ts_rank` has no
 * notion of rarity, which together lose most of what a lexical leg is for.
 *
 * Reads the indexed `content_tsv` column and is unavailable until its migration
 * has run: computing tsvectors on the fly means one sequential scan of every
 * chunk per query word, which on a real corpus stalls the database.
 */
final class TsVectorLexicalSearch implements LexicalSearch
{
    /** Longer questions are cut: past this, extra words only add noise and cost. */
    private const MAX_TERMS = 32;

    /** Words in more than this share of chunks are left to the vector leg: they barely rank, and matching them means scoring tens of thousands of chunks. */
    private const MAX_DOCUMENT_FREQUENCY = 0.08;

    /** Only the rarest words of a question are matched and scored. */
    private const MAX_SCORED_TERMS = 8;

    public function isAvailable(): bool
    {
        $connection = $this->connection();

        return $connection->getDriverName() === 'pgsql' && FullText::installed($connection);
    }

    /**
     * @return array<int, int>
     */
    public function candidates(string $query, VectorQuery $filters, int $limit): array
    {
        $terms = self::terms($query);

        if ($terms === [] || ! $this->isAvailable()) {
            return [];
        }

        $timeout = (int) config('filament-ai.retrieval.hybrid.timeout_ms', 3000);

        // A slow keyword search must never hold up an answer or the database:
        // past the timeout the vector leg answers alone.
        try {
            return $this->connection()->transaction(function () use ($terms, $filters, $limit, $timeout): array {
                if ($timeout <= 0) {
                    return $this->search($terms, $filters, $limit);
                }

                // SET LOCAL outlives a savepoint: when the caller already had a
                // transaction open, put its own timeout back afterwards.
                $previous = (string) $this->connection()->selectOne('SHOW statement_timeout')->statement_timeout;
                $this->connection()->statement("SET LOCAL statement_timeout = {$timeout}");

                try {
                    return $this->search($terms, $filters, $limit);
                } finally {
                    $this->connection()->statement('SELECT set_config(?, ?, true)', ['statement_timeout', $previous]);
                }
            });
        } catch (QueryException $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param  list<string>  $terms
     * @return array<int, int>
     */
    private function search(array $terms, VectorQuery $filters, int $limit): array
    {
        $connection = $this->connection();
        $chunks = Tables::chunks();
        $documents = Tables::documents();
        $tsv = 'c.'.FullText::COLUMN;

        // Every tsquery goes into the statements below as a constant: a query
        // computed inside the statement (a CTE, a join) cannot use the GIN
        // index, and each term then costs a scan of the whole table.
        $queries = $this->tsqueries($terms);

        if ($queries === []) {
            return [];
        }

        $total = $this->corpusSize();
        $cap = $total >= 1000 ? (int) ceil($total * self::MAX_DOCUMENT_FREQUENCY) : null;
        $weights = [];

        foreach ($this->frequencies($queries, $cap) as $q => $df) {
            // A common word in a real corpus ranks little and only widens
            // the scan; it still counts through the vector leg.
            if ($df <= 0 || ($cap !== null && $df > $cap)) {
                continue;
            }

            $weights[$q] = log(1 + ($total - $df + 0.5) / ($df + 0.5));
        }

        // The rarest words carry the match; a long question's other words
        // would only make the final scan wider.
        arsort($weights);
        $weights = array_slice($weights, 0, self::MAX_SCORED_TERMS, true);

        if ($weights === []) {
            return [];
        }

        $scores = [];
        $scoreBindings = [];
        $used = [];

        foreach ($weights as $q => $idf) {
            $scores[] = "CASE WHEN {$tsv} @@ ?::tsquery THEN ?::float8 ELSE 0 END";
            $scoreBindings[] = (string) $q;
            $scoreBindings[] = $idf;
            $used[] = "({$q})";
        }

        [$where, $filterBindings] = $this->filters($filters);

        $rows = $connection->select(
            'SELECT c.id, ('.implode(' + ', $scores).") AS score
            FROM {$chunks} c
            JOIN {$documents} d ON d.id = c.document_id
            WHERE {$tsv} @@ ?::tsquery AND c.embedded_at IS NOT NULL{$where}
            ORDER BY score DESC, c.id
            LIMIT ?",
            [...$scoreBindings, implode(' | ', $used), ...$filterBindings, $limit],
        );

        return array_map(static fn (object $row): int => (int) $row->id, $rows);
    }

    /**
     * How many chunks each query occurs in: from the statistics table that
     * `ai:fulltext` fills, and counted live for words it does not know yet
     * (all of them before the first refresh), stopping at `$cap`.
     *
     * @param  list<string>  $queries
     * @return array<string, int>
     */
    private function frequencies(array $queries, ?int $cap): array
    {
        $connection = $this->connection();
        $known = [];
        $words = [];

        foreach ($queries as $q) {
            // A single lexeme, as plainto_tsquery prints it: 'word'.
            if (preg_match("/^'((?:[^']|'')+)'$/u", $q, $match) === 1) {
                $words[str_replace("''", "'", $match[1])] = $q;
            }
        }

        if ($words !== [] && $this->hasStatistics()) {
            $rows = $connection->table(Tables::lexemes())->whereIn('word', array_keys($words))->get(['word', 'ndoc']);

            foreach ($rows as $row) {
                $known[$words[$row->word]] = (int) $row->ndoc;
            }
        }

        $frequencies = [];

        foreach ($queries as $q) {
            if (isset($known[$q])) {
                $frequencies[$q] = $known[$q];

                continue;
            }

            $count = 'SELECT 1 FROM '.Tables::chunks().' c WHERE c.'.FullText::COLUMN.' @@ ?::tsquery';

            $frequencies[$q] = (int) $connection->selectOne(
                'SELECT count(*) AS n FROM ('.$count.($cap === null ? '' : ' LIMIT '.($cap + 1)).') s',
                [$q],
            )->n;
        }

        return $frequencies;
    }

    private function hasStatistics(): bool
    {
        $connection = $this->connection();

        return $connection->selectOne('SELECT to_regclass(?) AS t', [Tables::lexemes()])->t !== null
            && $connection->table(Tables::lexemes())->exists();
    }

    /**
     * The planner's row estimate is free and close enough for a logarithm;
     * a never-analysed table (-1, or 0) is counted instead.
     */
    private function corpusSize(): float
    {
        $estimate = (float) ($this->connection()->selectOne(
            'SELECT reltuples AS n FROM pg_class WHERE oid = to_regclass(?)',
            [Tables::chunks()],
        )->n ?? 0);

        return max(1.0, $estimate > 0 ? $estimate : (float) $this->connection()->table(Tables::chunks())->count());
    }

    /**
     * Each word as the language's tsquery (stemmed, accents folded), stop
     * words and duplicates dropped.
     *
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function tsqueries(array $terms): array
    {
        $language = FullText::language();
        $placeholders = implode(', ', array_fill(0, count($terms), '(?)'));

        $rows = $this->connection()->select(
            "SELECT DISTINCT q FROM (
                SELECT plainto_tsquery('{$language}'::regconfig, ".FullText::FUNCTION."(w))::text AS q
                FROM (VALUES {$placeholders}) AS t(w)
            ) s WHERE q <> ''",
            $terms,
        );

        return array_map(static fn (object $row): string => (string) $row->q, $rows);
    }

    /**
     * The words of a query, lower-cased and de-duplicated. Stop words are left
     * in: `plainto_tsquery` drops them for the configured language.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[\'\x{2019}][\p{L}]+)?/u', mb_strtolower($query), $matches);

        $terms = [];

        foreach ($matches[0] as $word) {
            // Elided articles ("dell'ingenuo") carry the word after the apostrophe.
            $word = (string) preg_replace('/^\p{L}{1,4}[\'\x{2019}]/u', '', $word);

            if (mb_strlen($word) < 2) {
                continue;
            }

            $terms[$word] = true;
        }

        // Keys that look like integers ("1754") come back as ints: cast, or a
        // year in a question breaks every strict caller downstream.
        return array_map(strval(...), array_slice(array_keys($terms), 0, self::MAX_TERMS));
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function filters(VectorQuery $filters): array
    {
        $sql = '';
        $bindings = [];

        $in = static function (string $column, array $values) use (&$sql, &$bindings): void {
            // An empty list closes the door, as in the vector store.
            if ($values === []) {
                $sql .= ' AND 0 = 1';

                return;
            }

            $sql .= " AND {$column} IN (".implode(', ', array_fill(0, count($values), '?')).')';
            array_push($bindings, ...array_values($values));
        };

        if ($filters->sourceKeys !== null) {
            $in('c.source_key', $filters->sourceKeys);
        }

        if ($filters->documentIds !== null) {
            $in('c.document_id', $filters->documentIds);
        }

        if ($filters->externalIds !== null) {
            $in('d.external_id', array_map(strval(...), $filters->externalIds));
        }

        if ($filters->positionFrom !== null) {
            $sql .= ' AND c.position_end >= ?';
            $bindings[] = $filters->positionFrom;
        }

        if ($filters->positionTo !== null) {
            $sql .= ' AND c.position_start <= ?';
            $bindings[] = $filters->positionTo;
        }

        return [$sql, $bindings];
    }

    private function connection(): Connection
    {
        /** @var Connection */
        return DB::connection(Tables::connection());
    }
}
