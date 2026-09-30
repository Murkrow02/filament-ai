<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Iterative solving left the package in 6.0.0: an application that wants it
 * owns the method and the tables. Attempts go first, they reference runs.
 *
 * Not reversible on purpose: what was in them belongs to the application that
 * ran them, and this package no longer knows their shape.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::dropIfExists(Tables::name('solve_attempts'));
        Schema::dropIfExists(Tables::name('solve_runs'));
    }

    public function down(): void
    {
        //
    }
};
