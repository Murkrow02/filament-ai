<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\VectorStores;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Contracts\ResizableVectorStore;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Embeddings\VectorMath;
use Murkrow\FilamentAi\Exceptions\VectorDriverUnsupportedException;
use Murkrow\FilamentAi\Support\Tables;

/**
 * pgvector-backed store: the database does the ranking.
 *
 * Similarity is expressed as `1 - (embedding <=> :q)`, which is exactly cosine
 * similarity because vectors are L2-normalised on write. Ordering is done on
 * the raw distance operator rather than on the derived score so the HNSW index
 * is actually used -- ordering by `1 - distance DESC` would defeat it.
 */
final class PgVectorStore extends AbstractVectorStore implements ResizableVectorStore
{
    /** @var array<int, true> */
    private static array $tuned = [];

    public function name(): string
    {
        return 'pgvector';
    }

    public function installSchema(Blueprint $table, int $dimensions): void
    {
        if (! Blueprint::hasMacro($this->columnType())) {
            throw new VectorDriverUnsupportedException(
                'The pgvector/pgvector package does not appear to be installed: '
                ."Blueprint::{$this->columnType()}() is unavailable."
            );
        }

        $table->{$this->columnType()}('embedding', $dimensions)->nullable();
    }

    public function installIndexes(int $dimensions): void
    {
        $index = (string) $this->option('index', 'hnsw');

        if ($index === 'none') {
            return;
        }

        $chunks = Tables::chunks();
        $name = $chunks.'_embedding_'.$index;
        $ops = (string) $this->option('ops', 'vector_cosine_ops');

        if ($index === 'ivfflat') {
            $lists = (int) $this->option('ivfflat.lists', 1000);

            $this->connection()->statement(
                "CREATE INDEX IF NOT EXISTS {$name} ON {$chunks} USING ivfflat (embedding {$ops}) WITH (lists = {$lists})"
            );

            return;
        }

        $m = (int) $this->option('hnsw.m', 16);
        $efConstruction = (int) $this->option('hnsw.ef_construction', 64);

        $this->connection()->statement(
            "CREATE INDEX IF NOT EXISTS {$name} ON {$chunks} USING hnsw (embedding {$ops}) WITH (m = {$m}, ef_construction = {$efConstruction})"
        );
    }

    public function dropIndexes(): void
    {
        $chunks = Tables::chunks();

        foreach (['hnsw', 'ivfflat'] as $index) {
            $this->connection()->statement("DROP INDEX IF EXISTS {$chunks}_embedding_{$index}");
        }
    }

    public function installedDimensions(): ?int
    {
        // For vector and halfvec, atttypmod is the declared width itself.
        $row = $this->connection()->selectOne(
            'SELECT atttypmod FROM pg_attribute
             WHERE attrelid = to_regclass(?) AND attname = ? AND NOT attisdropped',
            [Tables::chunks(), 'embedding'],
        );

        if ($row === null) {
            return null;
        }

        $width = (int) $row->atttypmod;

        return $width > 0 ? $width : null;
    }

    public function resize(int $dimensions): void
    {
        $chunks = Tables::chunks();
        $type = $this->columnType();
        $connection = $this->connection();

        $connection->transaction(static function () use ($connection, $chunks, $type, $dimensions): void {
            $connection->table($chunks)
                ->where(static fn ($query) => $query->whereNotNull('embedding')->orWhereNotNull('embedded_at'))
                ->update([
                    'embedding' => null,
                    'embedding_model' => null,
                    'embedding_dimensions' => null,
                    'embedded_at' => null,
                ]);

            // USING NULL rather than a cast: the old vectors are already gone,
            // and a cast between widths is exactly what pgvector rejects.
            $connection->statement(
                "ALTER TABLE {$chunks} ALTER COLUMN embedding TYPE {$type}({$dimensions}) USING NULL"
            );
        });
    }

    public function assertSupported(): void
    {
        $connection = $this->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            throw VectorDriverUnsupportedException::connection('pgvector', $connection->getDriverName());
        }

        $installed = $connection->selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'vector'");

        if ($installed === null) {
            throw VectorDriverUnsupportedException::extensionMissing();
        }
    }

    /**
     * Enable the extension. Requires a superuser or an image that pre-installs
     * it; `rag:install` calls this and reports the failure in plain language.
     */
    public function createExtension(): void
    {
        $this->connection()->statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    /**
     * @param  iterable<int, array{id: int, vector: array<int, float>}>  $vectors
     */
    public function upsert(iterable $vectors, string $model, int $dimensions): void
    {
        $rows = [];

        foreach ($vectors as $entry) {
            $rows[] = [(int) $entry['id'], VectorMath::toPgLiteral($entry['vector'])];
        }

        if ($rows === []) {
            return;
        }

        $chunks = Tables::chunks();
        $type = $this->columnType();

        // One statement for the whole batch: a VALUES list joined against the
        // table beats 96 round trips per job by roughly an order of magnitude.
        $placeholders = [];
        $bindings = [];

        foreach ($rows as $index => [$id, $literal]) {
            $placeholders[] = $index === 0
                ? '(?::bigint, ?::text)'
                : '(?, ?)';

            $bindings[] = $id;
            $bindings[] = $literal;
        }

        $values = implode(', ', $placeholders);

        $sql = <<<SQL
            UPDATE {$chunks} AS c
            SET embedding = v.embedding::{$type},
                embedding_model = ?,
                embedding_dimensions = ?,
                embedded_at = NOW()
            FROM (VALUES {$values}) AS v(id, embedding)
            WHERE c.id = v.id
        SQL;

        $this->connection()->update($sql, [$model, $dimensions, ...$bindings]);
    }

    /**
     * @param  array<int, int>  $chunkIds
     */
    public function forget(array $chunkIds): void
    {
        if ($chunkIds === []) {
            return;
        }

        // Deleting the chunk row already removes the vector; this exists for
        // callers that want to invalidate an embedding without losing the text.
        $this->connection()->table(Tables::chunks())
            ->whereIn('id', $chunkIds)
            ->update([
                'embedding' => null,
                'embedding_model' => null,
                'embedding_dimensions' => null,
                'embedded_at' => null,
            ]);
    }

    /**
     * @return Collection<int, ScoredChunk>
     */
    public function search(VectorQuery $query): Collection
    {
        if ($query->vector === []) {
            return collect();
        }

        $this->tuneSession();

        $literal = VectorMath::toPgLiteral($query->vector);
        $type = $this->columnType();

        $builder = $this->baseQuery($query)
            ->select([
                ...$this->selectColumns(),
                DB::raw("1 - (c.embedding <=> ?::{$type}) as score"),
            ])
            ->addBinding($literal, 'select')
            // Order on the distance operator itself so the ANN index is used.
            ->orderByRaw("c.embedding <=> ?::{$type}", [$literal])
            ->limit($query->limit);

        if ($query->minScore !== null) {
            // Expressed as a distance bound for the same reason.
            $builder->whereRaw("c.embedding <=> ?::{$type} <= ?", [$literal, 1 - $query->minScore]);
        }

        return collect($builder->get()->all())
            ->map(fn (object $row): ScoredChunk => $this->toScoredChunk($row, (float) $row->score))
            ->values();
    }

    /**
     * @param  array<int, int>  $chunkIds
     * @return array<int, array<int, float>>
     */
    public function read(array $chunkIds): array
    {
        if ($chunkIds === []) {
            return [];
        }

        $rows = $this->connection()->table(Tables::chunks())
            ->whereIn('id', $chunkIds)
            ->whereNotNull('embedding')
            ->select(['id', DB::raw('embedding::text as embedding_text')])
            ->get();

        $vectors = [];

        foreach ($rows as $row) {
            $vectors[(int) $row->id] = VectorMath::fromPgLiteral((string) $row->embedding_text);
        }

        return $vectors;
    }

    public function countEmbedded(?string $sourceKey = null): int
    {
        $query = $this->connection()->table(Tables::chunks())->whereNotNull('embedded_at');

        if ($sourceKey !== null) {
            $query->where('source_key', $sourceKey);
        }

        return $query->count();
    }

    /**
     * HNSW trades recall for speed through ef_search. Set once per connection:
     * a restrictive WHERE can make the index under-return, and raising this is
     * the documented remedy.
     */
    private function tuneSession(): void
    {
        $connection = $this->connection();
        $id = spl_object_id($connection);

        if (isset(self::$tuned[$id])) {
            return;
        }

        self::$tuned[$id] = true;

        if ((string) $this->option('index', 'hnsw') === 'ivfflat') {
            $probes = (int) $this->option('ivfflat.probes', 10);
            $connection->statement("SET ivfflat.probes = {$probes}");

            return;
        }

        $efSearch = (int) $this->option('hnsw.ef_search', 100);
        $connection->statement("SET hnsw.ef_search = {$efSearch}");
    }

    private function columnType(): string
    {
        $type = (string) $this->option('type', 'vector');

        return in_array($type, ['vector', 'halfvec'], true) ? $type : 'vector';
    }

    private function option(string $key, mixed $default = null): mixed
    {
        return config("rag.vector.drivers.pgvector.{$key}", $default);
    }

    private function connection(): Connection
    {
        /** @var Connection */
        return DB::connection(Tables::connection());
    }
}
