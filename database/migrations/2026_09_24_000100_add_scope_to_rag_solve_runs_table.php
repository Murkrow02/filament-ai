<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Support\Tables;

/**
 * The panel and tenant a run was started in.
 *
 * Attempts run on a queue worker, where no panel is current and nobody is
 * signed in. Without these the resource tools either vanish or -- worse --
 * read across tenants; with them each attempt is put back where the question
 * was asked, as the user who asked it.
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
            $table->json('scope')->nullable()->after('context');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::solveRuns(), function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }
};
