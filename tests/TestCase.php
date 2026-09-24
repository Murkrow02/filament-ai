<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\FilamentAiServiceProvider;
use Murkrow\FilamentAi\Tests\Fixtures\InMemoryVectorStore;
use Murkrow\FilamentAi\Tests\Fixtures\TestBookSource;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case.
 *
 * Runs on SQLite with a fake embedding provider and an in-memory vector store,
 * so the whole pipeline -- chunking, diffing, retrieval, answering -- is
 * exercised with no database extension, no API key and no network. Tests that
 * genuinely need pgvector live in PgVectorStoreTest and skip themselves when a
 * PostgreSQL connection is not configured.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createHostSchema();
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    /**
     * Testbench does not auto-discover the providers of the package's own
     * dependencies, so the ones the package integrates with are listed here
     * explicitly -- exactly what a real host gets for free from discovery.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Deliberately not PgvectorServiceProvider: it ships a
        // CREATE EXTENSION migration that SQLite cannot run, and the Blueprint
        // macros it registers are registered by FilamentAiServiceProvider anyway.
        return array_values(array_filter([
            class_exists(McpServiceProvider::class)
                ? McpServiceProvider::class
                : null,
            AiServiceProvider::class,
            FilamentAiServiceProvider::class,
        ]));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('filament-ai.queue.connection', 'sync');
        $app['config']->set('filament-ai.queue.queue', 'default');

        $app['config']->set('filament-ai.embeddings.driver', 'fake');
        $app['config']->set('filament-ai.embeddings.dimensions', 64);
        $app['config']->set('filament-ai.embeddings.model', 'fake-embedding');
        $app['config']->set('filament-ai.llm.driver', 'fake');
        $app['config']->set('filament-ai.settings.enabled', false);
        $app['config']->set('filament-ai.mcp.enabled', false);
        $app['config']->set('filament-ai.filament.enabled', false);
        $app['config']->set('filament-ai.vector.driver', 'memory');

        // FakeEmbeddingProvider is deterministic but not semantic, so the score
        // floor tuned for a real model would reject everything. Tests that care
        // about thresholds set their own.
        $app['config']->set('filament-ai.retrieval.min_score', 0.0);

        $app['config']->set('filament-ai.sources', [TestBookSource::class]);

        // SQLite has no vector type, so the vector column migration delegates
        // to a store that keeps vectors in a plain JSON column instead.
        $app->singleton(VectorStore::class, static fn (): VectorStore => new InMemoryVectorStore);
    }

    /**
     * Framework queue tables, created on demand by the tests that exercise the
     * real queued path. The sync driver cannot stand in for it: it runs batch
     * jobs inside Batch::add(), before the batch's pending count is settled, so
     * completion callbacks never fire the way they do in production.
     */
    protected function createQueueTables(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Drain the queue, including the jobs that earlier jobs enqueue -- the
     * ingestion pipeline deliberately hands off from one batch to the next.
     */
    protected function drainQueue(string $queue = 'rag', int $passes = 10): void
    {
        for ($pass = 0; $pass < $passes; $pass++) {
            $this->artisan('queue:work', [
                'connection' => 'database',
                '--queue' => $queue,
                '--stop-when-empty' => true,
                // Without this the worker idles for its default sleep before
                // noticing the queue is empty, three seconds at a time.
                '--sleep' => 0,
                '--tries' => 1,
            ])->run();

            if (DB::table('jobs')->count() === 0) {
                return;
            }
        }
    }

    /**
     * The host-application tables the "books" fixture source reads from.
     */
    protected function createHostSchema(): void
    {
        Schema::create('test_books', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('author')->nullable();
            $table->boolean('bad_ocr')->default(false);
            $table->timestamps();
        });

        Schema::create('test_teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('test_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_team_id')->nullable();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('test_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->nullable();
            $table->string('secret')->nullable();
            $table->string('locked')->nullable();
            $table->string('password')->nullable();
            $table->string('code')->nullable();
            $table->foreignId('test_book_id')->nullable();
            $table->date('published_on')->nullable();
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
}
