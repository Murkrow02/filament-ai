<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Support\Tables;

/**
 * The method a run was started with, next to the assistant it used.
 *
 * Stored per run rather than read from the config at each wave: the config can
 * change between two waves of the same run, and a run that changed method
 * halfway cannot be read afterwards.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::table(Tables::solveRuns(), function (Blueprint $table): void {
            $table->string('strategy', 191)->nullable()->after('assistant');
        });

        Schema::table(Tables::solveAttempts(), function (Blueprint $table): void {
            // What this attempt was told to do, so the panel can say "wave 2:
            // search the archive" instead of "wave 2".
            $table->string('phase', 80)->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::solveRuns(), function (Blueprint $table): void {
            $table->dropColumn('strategy');
        });

        Schema::table(Tables::solveAttempts(), function (Blueprint $table): void {
            $table->dropColumn('phase');
        });
    }
};
