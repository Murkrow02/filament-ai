<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Support\FullText;
use Murkrow\FilamentAi\Support\Tables;

/**
 * An indexed tsvector of each chunk, kept by a trigger, for the lexical leg of
 * hybrid retrieval. Postgres only; other databases skip it and the tsvector
 * driver reports itself unavailable there.
 *
 * Accents are folded through `unaccent` when the extension can be created
 * (OCR and old spelling are inconsistent about them), and not otherwise: the
 * wrapper function exists either way, so the column definition does not
 * depend on what the server happened to have installed.
 */
return new class extends Migration
{
    private const INLINE_BACKFILL_LIMIT = 20000;

    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection(Tables::connection());

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        FullText::install($connection);

        // A small corpus is filled here. A large one is left to
        // `php artisan ai:fulltext`, which fills it in batches outside the
        // migration's transaction; until then the lexical leg only sees the
        // chunks written since this migration ran.
        if ($connection->table(Tables::chunks())->count() <= self::INLINE_BACKFILL_LIMIT) {
            FullText::backfill($connection);
            FullText::refreshStatistics($connection);
        }
    }

    public function down(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection(Tables::connection());

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        FullText::uninstall($connection);
    }
};
