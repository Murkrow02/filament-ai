<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Support\Tables;

/**
 * The vector payload is driver-specific, so this migration hardcodes nothing:
 * it resolves the configured store and lets it install its own columns and
 * indexes. Swapping drivers is therefore a migration, never a re-embed.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        $store = app(VectorStore::class);
        $store->assertSupported();

        $dimensions = $store->dimensions();

        Schema::table(Tables::chunks(), function (Blueprint $table) use ($store, $dimensions): void {
            $store->installSchema($table, $dimensions);
        });

        // Build the ANN index after the column exists. On an empty table this
        // is instant; `rag:vector:reindex` rebuilds it after a bulk load.
        $store->installIndexes($dimensions);
    }

    public function down(): void
    {
        $store = app(VectorStore::class);
        $store->dropIndexes();

        Schema::table(Tables::chunks(), function (Blueprint $table): void {
            $table->dropColumn('embedding');
        });
    }
};
