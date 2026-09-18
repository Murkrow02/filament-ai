<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/**
 * Base case for the tests that must run against real PostgreSQL and pgvector.
 *
 * Everything else runs on SQLite for speed, but the pgvector driver's whole
 * value is that the database does the ranking -- an in-memory substitute would
 * assert nothing about the SQL that actually ships. These tests skip
 * themselves when no PostgreSQL is reachable, so the suite stays runnable
 * anywhere.
 */
abstract class PostgresTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! self::postgresAvailable()) {
            $this->markTestSkipped(
                'No PostgreSQL with pgvector reachable at '.self::dsn().'. '
                .'Set FILAMENT_AI_TEST_PG_HOST / FILAMENT_AI_TEST_PG_PORT to point at one.'
            );
        }

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'pgsql',
            'host' => self::host(),
            'port' => self::port(),
            'database' => env('FILAMENT_AI_TEST_PG_DATABASE', 'rag_test'),
            'username' => env('FILAMENT_AI_TEST_PG_USERNAME', 'rag'),
            'password' => env('FILAMENT_AI_TEST_PG_PASSWORD', 'rag'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        // The real driver, against the real extension.
        $app['config']->set('filament-ai.vector.driver', 'pgvector');
        $app->forgetInstance(\Murkrow\FilamentAi\Contracts\VectorStore::class);
        $app->singleton(
            \Murkrow\FilamentAi\Contracts\VectorStore::class,
            static fn (): \Murkrow\FilamentAi\Contracts\VectorStore => new \Murkrow\FilamentAi\VectorStores\PgVectorStore,
        );
    }

    /**
     * Postgres keeps its schema between runs, so start from a clean slate.
     */
    protected function createHostSchema(): void
    {
        DB::statement('DROP SCHEMA public CASCADE');
        DB::statement('CREATE SCHEMA public');
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('test_books', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('author')->nullable();
            $table->boolean('bad_ocr')->default(false);
            $table->timestamps();
        });

        Schema::create('test_book_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_book_id')->constrained('test_books')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->longText('content');
            $table->timestamps();
        });
    }

    protected static function host(): string
    {
        return (string) env('FILAMENT_AI_TEST_PG_HOST', 'fai-test-pg');
    }

    protected static function port(): int
    {
        return (int) env('FILAMENT_AI_TEST_PG_PORT', 5432);
    }

    protected static function dsn(): string
    {
        return self::host().':'.self::port();
    }

    protected static function postgresAvailable(): bool
    {
        try {
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s', self::host(), self::port(), env('FILAMENT_AI_TEST_PG_DATABASE', 'rag_test')),
                (string) env('FILAMENT_AI_TEST_PG_USERNAME', 'rag'),
                (string) env('FILAMENT_AI_TEST_PG_PASSWORD', 'rag'),
                [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');

            return $pdo->query("SELECT 1 FROM pg_extension WHERE extname = 'vector'")->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
