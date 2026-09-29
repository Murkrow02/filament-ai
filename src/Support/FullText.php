<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Support;

use Closure;
use Illuminate\Database\Connection;
use Throwable;

/**
 * The Postgres full-text column behind the tsvector lexical driver.
 *
 * `content_tsv` is a plain column kept current by a trigger, so ingestion never
 * has to know it exists. Not a generated column on purpose: adding one rewrites
 * the whole chunk table under an exclusive lock, minutes on a large corpus,
 * while a nullable column is added instantly and filled in small batches by
 * `backfill()` without blocking readers or ingestion.
 *
 * Text goes through `filament_ai_unaccent()`, which folds accents when the
 * `unaccent` extension is available and is the identity otherwise.
 */
final class FullText
{
    public const COLUMN = 'content_tsv';

    public const FUNCTION = 'filament_ai_unaccent';

    public const TRIGGER_FUNCTION = 'filament_ai_chunk_tsv';

    public static function install(Connection $connection, ?string $language = null): void
    {
        $language ??= self::language();
        $chunks = Tables::chunks();
        $unaccent = self::unaccentSchema($connection);

        $body = $unaccent === null
            ? 'SELECT $1'
            : "SELECT {$unaccent}.unaccent('{$unaccent}.unaccent'::regdictionary, \$1)";

        $connection->statement(
            'CREATE OR REPLACE FUNCTION '.self::FUNCTION.'(text) RETURNS text '
            ."LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS \$fn\$ {$body} \$fn\$"
        );

        $connection->statement("ALTER TABLE {$chunks} ADD COLUMN IF NOT EXISTS ".self::COLUMN.' tsvector');

        $connection->statement(
            'CREATE OR REPLACE FUNCTION '.self::TRIGGER_FUNCTION.'() RETURNS trigger LANGUAGE plpgsql AS $fn$ '
            .'BEGIN NEW.'.self::COLUMN.' := '.self::expression('NEW.content', $language).'; RETURN NEW; END $fn$'
        );

        $connection->statement("DROP TRIGGER IF EXISTS {$chunks}_".self::COLUMN." ON {$chunks}");
        $connection->statement(
            "CREATE TRIGGER {$chunks}_".self::COLUMN." BEFORE INSERT OR UPDATE OF content ON {$chunks} "
            .'FOR EACH ROW EXECUTE FUNCTION '.self::TRIGGER_FUNCTION.'()'
        );

        $connection->statement(
            "CREATE INDEX IF NOT EXISTS {$chunks}_".self::COLUMN."_gin ON {$chunks} USING gin (".self::COLUMN.')'
        );

        $connection->statement(
            'CREATE TABLE IF NOT EXISTS '.Tables::lexemes().' (word text PRIMARY KEY, ndoc integer NOT NULL)'
        );
    }

    /**
     * Recount how many chunks each lexeme occurs in. Weighting a query word
     * by its rarity needs this number; counting it live costs a heap visit
     * per matching chunk, seconds for a common word on a large corpus. A
     * stale count is harmless: rarity barely moves as a corpus grows.
     */
    public static function refreshStatistics(Connection $connection): int
    {
        $chunks = Tables::chunks();
        $lexemes = Tables::lexemes();

        return $connection->transaction(static function () use ($connection, $chunks, $lexemes): int {
            $connection->statement("DELETE FROM {$lexemes}");

            return $connection->affectingStatement(
                "INSERT INTO {$lexemes} (word, ndoc) SELECT word, ndoc FROM ts_stat("
                ."'SELECT ".self::COLUMN." FROM {$chunks} WHERE ".self::COLUMN." IS NOT NULL')"
            );
        });
    }

    /**
     * Fill the column for rows that lack it (or every row, to rebuild after a
     * language change), a batch at a time so no statement holds many locks.
     *
     * @param  (Closure(int $done): void)|null  $progress
     * @return int rows updated
     */
    public static function backfill(Connection $connection, bool $all = false, int $batch = 2000, ?Closure $progress = null): int
    {
        $chunks = Tables::chunks();
        $expression = self::expression('c.content', self::language());
        $done = 0;
        $after = 0;

        while (true) {
            // An id cursor never revisits a row, and works for a rebuild,
            // where there are no nulls to look for.
            $ids = $connection->table($chunks)
                ->where('id', '>', $after)
                ->when(! $all, static fn ($query) => $query->whereNull(self::COLUMN))
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return $done;
            }

            $after = (int) end($ids);

            $done += $connection->update(
                "UPDATE {$chunks} AS c SET ".self::COLUMN." = {$expression} WHERE c.id = ANY(?::bigint[])",
                ['{'.implode(',', array_map(intval(...), $ids)).'}'],
            );

            if ($progress !== null) {
                $progress($done);
            }
        }
    }

    public static function uninstall(Connection $connection): void
    {
        $chunks = Tables::chunks();

        $connection->statement('DROP TABLE IF EXISTS '.Tables::lexemes());
        $connection->statement("DROP TRIGGER IF EXISTS {$chunks}_".self::COLUMN." ON {$chunks}");
        $connection->statement('DROP FUNCTION IF EXISTS '.self::TRIGGER_FUNCTION.'()');
        $connection->statement("DROP INDEX IF EXISTS {$chunks}_".self::COLUMN.'_gin');
        $connection->statement("ALTER TABLE {$chunks} DROP COLUMN IF EXISTS ".self::COLUMN);
        $connection->statement('DROP FUNCTION IF EXISTS '.self::FUNCTION.'(text)');
    }

    public static function installed(Connection $connection): bool
    {
        static $known = [];

        $key = $connection->getName().'|'.Tables::chunks();

        return $known[$key] ??= $connection->selectOne(
            'SELECT 1 AS ok FROM pg_attribute WHERE attrelid = to_regclass(?) AND attname = ? AND NOT attisdropped',
            [Tables::chunks(), self::COLUMN],
        ) !== null;
    }

    public static function language(): string
    {
        $language = (string) config('filament-ai.retrieval.hybrid.tsvector_language', 'simple');

        return preg_match('/^[a-z_]+$/', $language) === 1 ? $language : 'simple';
    }

    private static function expression(string $column, string $language): string
    {
        return "to_tsvector('{$language}'::regconfig, ".self::FUNCTION."(coalesce({$column}, '')))";
    }

    /**
     * The schema `unaccent` lives in, creating the extension if the server
     * ships it and the role may. Null when it cannot be had.
     */
    private static function unaccentSchema(Connection $connection): ?string
    {
        if (! (bool) config('filament-ai.retrieval.hybrid.unaccent', true)) {
            return null;
        }

        $schema = static fn (): ?string => $connection->selectOne(
            "SELECT n.nspname AS schema FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'unaccent'"
        )?->schema;

        if (($found = $schema()) !== null) {
            return $found;
        }

        try {
            // A savepoint, so a refused CREATE EXTENSION does not abort the
            // migration's surrounding transaction.
            $connection->transaction(static fn () => $connection->statement('CREATE EXTENSION IF NOT EXISTS unaccent'));
        } catch (Throwable) {
            return null;
        }

        return $schema();
    }
}
