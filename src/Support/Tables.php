<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Support;

/**
 * Resolves the package's table names from configuration.
 *
 * Every table sits behind `rag.database.prefix` so the package can never
 * collide with the host application's schema, and each individual name stays
 * overridable in case a host already owns one of the defaults.
 */
final class Tables
{
    public static function name(string $key): string
    {
        $prefix = (string) config('rag.database.prefix', 'rag_');
        $table = (string) config("rag.database.tables.{$key}", $key);

        return $prefix.$table;
    }

    public static function documents(): string
    {
        return self::name('documents');
    }

    public static function chunks(): string
    {
        return self::name('chunks');
    }

    public static function runs(): string
    {
        return self::name('runs');
    }

    public static function runItems(): string
    {
        return self::name('run_items');
    }

    public static function settings(): string
    {
        return self::name('settings');
    }

    public static function queries(): string
    {
        return self::name('queries');
    }

    public static function citations(): string
    {
        return self::name('citations');
    }

    public static function conversations(): string
    {
        return self::name('conversations');
    }

    public static function solveRuns(): string
    {
        return self::name('solve_runs');
    }

    public static function solveAttempts(): string
    {
        return self::name('solve_attempts');
    }

    public static function connection(): ?string
    {
        $connection = config('rag.database.connection');

        return $connection === null ? null : (string) $connection;
    }
}
