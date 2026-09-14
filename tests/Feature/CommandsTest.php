<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Facades\Rag;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

// rag:install publishes config/rag.php into Testbench's skeleton, which lives
// in vendor/ and outlives the run. Left behind, the recursive config merge
// lets that stale copy override every later change to the package defaults.
afterEach(function (): void {
    @unlink(config_path('rag.php'));
});

function seedForCommands(string $title = 'Cronaca cittadina'): TestBook
{
    $book = TestBook::create(['title' => $title]);

    $book->pages()->create([
        'number' => 1,
        'content' => 'Il podesta convoco il consiglio generale. Le mura vennero rinforzate e le porte sbarrate al tramonto.',
    ]);
    $book->pages()->create([
        'number' => 2,
        'content' => 'Il grano venne razionato per tutto inverno. I mercanti protestarono davanti al palazzo comunale.',
    ]);

    return $book;
}

it('lists the configured sources', function (): void {
    seedForCommands();

    $this->artisan('rag:sources')
        ->expectsOutputToContain('books')
        ->assertExitCode(0);
});

it('ingests synchronously from the command line', function (): void {
    seedForCommands();

    $this->artisan('rag:ingest', ['source' => 'books', '--sync' => true])
        ->assertExitCode(0);

    expect(Document::query()->count())->toBe(1)
        ->and(Chunk::query()->whereNotNull('embedded_at')->count())->toBeGreaterThan(0);
});

it('estimates without queuing anything on a dry run', function (): void {
    seedForCommands();

    $this->artisan('rag:ingest', ['source' => 'books', '--dry-run' => true])
        ->expectsOutputToContain('estimated cost')
        ->assertExitCode(0);

    expect(Document::query()->count())->toBe(0);
});

it('refuses an unknown source with a helpful message', function (): void {
    $this->artisan('rag:ingest', ['source' => 'nope'])
        ->expectsOutputToContain('Unknown source')
        ->assertExitCode(1);
});

it('rejects an invalid mode', function (): void {
    $this->artisan('rag:ingest', ['source' => 'books', '--mode' => 'sideways'])
        ->assertExitCode(1);
});

it('applies a cli filter', function (): void {
    $first = seedForCommands('Primo');
    seedForCommands('Secondo');

    $this->artisan('rag:ingest', [
        'source' => 'books',
        '--sync' => true,
        '--filter' => ['ids:'.$first->id],
    ])->assertExitCode(0);

    expect(Document::query()->count())->toBe(1)
        ->and(Document::query()->first()->title)->toBe('Primo');
});

it('searches from the command line', function (): void {
    seedForCommands();
    Rag::ingestSync('books');

    $this->artisan('rag:search', ['query' => ['mura', 'e', 'porte']])
        ->expectsOutputToContain('Cronaca cittadina')
        ->assertExitCode(0);
});

it('reports when a search matches nothing', function (): void {
    seedForCommands();
    Rag::ingestSync('books');

    $this->artisan('rag:search', ['query' => ['qualunque'], '--min-score' => '0.999'])
        ->expectsOutputToContain('No matching passages')
        ->assertExitCode(0);
});

it('answers a question from the command line', function (): void {
    seedForCommands();
    Rag::ingestSync('books');

    $this->artisan('rag:ask', ['question' => ['chi', 'convoco', 'il', 'consiglio']])
        ->expectsOutputToContain('Sources:')
        ->assertExitCode(0);
});

it('reports corpus status', function (): void {
    seedForCommands();
    Rag::ingestSync('books');

    $this->artisan('rag:status')
        ->expectsOutputToContain('documents')
        ->expectsOutputToContain('embedded')
        ->assertExitCode(0);
});

it('shows a single run by uuid prefix', function (): void {
    seedForCommands();
    $run = Rag::ingestSync('books');

    $this->artisan('rag:status', ['--run' => substr($run->uuid, 0, 8)])
        ->expectsOutputToContain($run->uuid)
        ->assertExitCode(0);
});

it('drops only the embeddings when asked', function (): void {
    seedForCommands();
    Rag::ingestSync('books');

    $chunks = Chunk::query()->count();

    $this->artisan('rag:purge', ['source' => 'books', '--embeddings-only' => true, '--force' => true])
        ->assertExitCode(0);

    expect(Chunk::query()->count())->toBe($chunks)
        ->and(Chunk::query()->whereNotNull('embedded_at')->count())->toBe(0);
});

it('purges documents and their chunks', function (): void {
    seedForCommands();
    Rag::ingestSync('books');

    $this->artisan('rag:purge', ['source' => 'books', '--force' => true])
        ->assertExitCode(0);

    expect(Document::query()->count())->toBe(0)
        ->and(Chunk::query()->count())->toBe(0);
});

it('says there is nothing to purge on an empty corpus', function (): void {
    $this->artisan('rag:purge', ['--force' => true])
        ->expectsOutputToContain('Nothing to purge')
        ->assertExitCode(0);
});

it('runs the installer against a supported store', function (): void {
    $this->artisan('rag:install', ['--skip-extension' => true])
        ->expectsOutputToContain('embedding model')
        ->expectsOutputToContain('chunks table')
        ->assertExitCode(0);
});

it('warns when no source is configured', function (): void {
    config()->set('rag.sources', []);
    app(\Murkrow\FilamentAi\Sources\SourceRegistry::class)->flush();

    $this->artisan('rag:install', ['--skip-extension' => true])
        ->expectsOutputToContain('none - generate one with rag:make:source')
        ->assertExitCode(0);
});

it('reports a vector store that cannot be used', function (): void {
    $this->artisan('rag:vector:install')->assertExitCode(0);
});
