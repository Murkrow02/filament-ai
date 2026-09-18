<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Support\Tables;
use Murkrow\FilamentAi\VectorStores\PgVectorStore;
use Throwable;

/**
 * Pre-flight check and setup.
 *
 * Every failure mode this reports is one that would otherwise surface as an
 * opaque SQL error halfway through a migration or, worse, halfway through a
 * paid ingestion run.
 */
class InstallCommand extends Command
{
    protected $signature = 'ai:install
                            {--force : Overwrite an existing published config file}
                            {--skip-extension : Do not attempt to create the pgvector extension}';

    protected $description = 'Publish the RAG config and verify the database can host the vector store';

    public function handle(VectorStore $store): int
    {
        $this->components->info('Installing murkrow/filament-ai');

        $this->publishConfig();

        $ok = $this->checkExtension($store);
        $ok = $this->checkJobBatches() && $ok;

        $this->newLine();
        $this->summary();

        if (! $ok) {
            $this->components->warn('Resolve the items above, then run `php artisan migrate`.');

            return self::FAILURE;
        }

        $this->components->info('Ready. Run `php artisan migrate` next.');

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'filament-ai-config',
            '--force' => $this->option('force') ? true : null,
        ]));

        $this->components->twoColumnDetail('config/filament-ai.php', file_exists(config_path('filament-ai.php')) ? '<fg=green>published</>' : '<fg=yellow>not published</>');
    }

    private function checkExtension(VectorStore $store): bool
    {
        if (! $store instanceof PgVectorStore) {
            $this->components->twoColumnDetail('vector store', '<fg=green>'.$store->name().'</>');

            return true;
        }

        if (! $this->option('skip-extension')) {
            try {
                $store->createExtension();
            } catch (Throwable $exception) {
                // Creating an extension needs elevated privileges; the check
                // below still passes if a DBA installed it for us.
                $this->components->twoColumnDetail(
                    'CREATE EXTENSION vector',
                    '<fg=yellow>skipped: '.$exception->getMessage().'</>',
                );
            }
        }

        try {
            $store->assertSupported();
        } catch (Throwable $exception) {
            $this->components->twoColumnDetail('pgvector', '<fg=red>unavailable</>');
            $this->components->error($exception->getMessage());

            return false;
        }

        $this->components->twoColumnDetail('pgvector extension', '<fg=green>available</>');

        return true;
    }

    private function checkJobBatches(): bool
    {
        $exists = Schema::hasTable('job_batches');

        $this->components->twoColumnDetail(
            'job_batches table',
            $exists ? '<fg=green>present</>' : '<fg=yellow>missing (the package ships a migration for it)</>',
        );

        return true;
    }

    private function summary(): void
    {
        $this->components->twoColumnDetail('embedding model', (string) config('filament-ai.embeddings.model'));
        $this->components->twoColumnDetail('dimensions', (string) config('filament-ai.embeddings.dimensions'));
        $this->components->twoColumnDetail('generation model', (string) config('filament-ai.llm.model'));
        $this->components->twoColumnDetail('queue', config('filament-ai.queue.connection').' / '.config('filament-ai.queue.queue'));
        $this->components->twoColumnDetail('tables prefix', (string) config('filament-ai.database.prefix'));
        $this->components->twoColumnDetail('chunks table', Tables::chunks());

        $sources = app(SourceRegistry::class)->keys();

        $this->components->twoColumnDetail(
            'configured sources',
            $sources === []
                ? '<fg=yellow>none - generate one with rag:make:source</>'
                : '<fg=green>'.implode(', ', $sources).'</>',
        );
    }
}
