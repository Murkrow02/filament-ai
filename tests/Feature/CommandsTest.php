<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

// ai:install publishes config/filament-ai.php into Testbench's skeleton, which lives
// in vendor/ and outlives the run. Left behind, the recursive config merge
// lets that stale copy override every later change to the package defaults.
afterEach(function (): void {
    @unlink(config_path('filament-ai.php'));
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

    $this->artisan('ai:sources')
        ->expectsOutputToContain('books')
        ->assertExitCode(0);
});

it('ingests synchronously from the command line', function (): void {
    seedForCommands();

    $this->artisan('ai:ingest', ['source' => 'books', '--sync' => true])
        ->assertExitCode(0);

    expect(Document::query()->count())->toBe(1)
        ->and(Chunk::query()->whereNotNull('embedded_at')->count())->toBeGreaterThan(0);
});

it('estimates without queuing anything on a dry run', function (): void {
    seedForCommands();

    $this->artisan('ai:ingest', ['source' => 'books', '--dry-run' => true])
        ->expectsOutputToContain('estimated cost')
        ->assertExitCode(0);

    expect(Document::query()->count())->toBe(0);
});

it('refuses an unknown source with a helpful message', function (): void {
    $this->artisan('ai:ingest', ['source' => 'nope'])
        ->expectsOutputToContain('Unknown source')
        ->assertExitCode(1);
});

it('rejects an invalid mode', function (): void {
    $this->artisan('ai:ingest', ['source' => 'books', '--mode' => 'sideways'])
        ->assertExitCode(1);
});

it('applies a cli filter', function (): void {
    $first = seedForCommands('Primo');
    seedForCommands('Secondo');

    $this->artisan('ai:ingest', [
        'source' => 'books',
        '--sync' => true,
        '--filter' => ['ids:'.$first->id],
    ])->assertExitCode(0);

    expect(Document::query()->count())->toBe(1)
        ->and(Document::query()->first()->title)->toBe('Primo');
});

it('searches from the command line', function (): void {
    seedForCommands();
    FilamentAi::ingestSync('books');

    $this->artisan('ai:search', ['query' => ['mura', 'e', 'porte']])
        ->expectsOutputToContain('Cronaca cittadina')
        ->assertExitCode(0);
});

it('reports when a search matches nothing', function (): void {
    seedForCommands();
    FilamentAi::ingestSync('books');

    $this->artisan('ai:search', ['query' => ['qualunque'], '--min-score' => '0.999'])
        ->expectsOutputToContain('No matching passages')
        ->assertExitCode(0);
});

it('answers a question from the command line', function (): void {
    seedForCommands();
    FilamentAi::ingestSync('books');

    $this->artisan('ai:ask', ['question' => ['chi', 'convoco', 'il', 'consiglio']])
        ->expectsOutputToContain('Sources:')
        ->assertExitCode(0);
});

it('reports corpus status', function (): void {
    seedForCommands();
    FilamentAi::ingestSync('books');

    $this->artisan('ai:status')
        ->expectsOutputToContain('documents')
        ->expectsOutputToContain('embedded')
        ->assertExitCode(0);
});

it('shows a single run by uuid prefix', function (): void {
    seedForCommands();
    $run = FilamentAi::ingestSync('books');

    $this->artisan('ai:status', ['--run' => substr($run->uuid, 0, 8)])
        ->expectsOutputToContain($run->uuid)
        ->assertExitCode(0);
});

it('drops only the embeddings when asked', function (): void {
    seedForCommands();
    FilamentAi::ingestSync('books');

    $chunks = Chunk::query()->count();

    $this->artisan('ai:purge', ['source' => 'books', '--embeddings-only' => true, '--force' => true])
        ->assertExitCode(0);

    expect(Chunk::query()->count())->toBe($chunks)
        ->and(Chunk::query()->whereNotNull('embedded_at')->count())->toBe(0);
});

it('purges documents and their chunks', function (): void {
    seedForCommands();
    FilamentAi::ingestSync('books');

    $this->artisan('ai:purge', ['source' => 'books', '--force' => true])
        ->assertExitCode(0);

    expect(Document::query()->count())->toBe(0)
        ->and(Chunk::query()->count())->toBe(0);
});

it('says there is nothing to purge on an empty corpus', function (): void {
    $this->artisan('ai:purge', ['--force' => true])
        ->expectsOutputToContain('Nothing to purge')
        ->assertExitCode(0);
});

it('runs the installer against a supported store', function (): void {
    $this->artisan('ai:install', ['--skip-extension' => true])
        ->expectsOutputToContain('embedding model')
        ->expectsOutputToContain('chunks table')
        ->assertExitCode(0);
});

it('warns when no source is configured', function (): void {
    config()->set('filament-ai.sources', []);
    app(\Murkrow\FilamentAi\Sources\SourceRegistry::class)->flush();

    $this->artisan('ai:install', ['--skip-extension' => true])
        ->expectsOutputToContain('none - generate one with rag:make:source')
        ->assertExitCode(0);
});

it('reports a vector store that cannot be used', function (): void {
    $this->artisan('ai:vector:install')->assertExitCode(0);
});

it('says out loud when a variable still uses the old name', function (): void {
    // A renamed variable does not fail, it is simply not read: the package
    // uses its own default instead, which is how an application configured
    // for one provider spent a day answering 401 from another.
    $_ENV['RAG_LLM_PROVIDER'] = 'deepseek';

    try {
        Artisan::call('ai:status');
        $output = Artisan::output();
    } finally {
        unset($_ENV['RAG_LLM_PROVIDER']);
    }

    expect($output)->toContain('no longer read')
        ->toContain('RAG_LLM_PROVIDER -> FILAMENT_AI_LLM_PROVIDER');
});

it('says nothing when every name is current', function (): void {
    Artisan::call('ai:status');

    expect(Artisan::output())->not->toContain('no longer read');
});
