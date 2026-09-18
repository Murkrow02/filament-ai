<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\VectorStores\PgVectorStore;
use Throwable;

class VectorInstallCommand extends Command
{
    protected $signature = 'ai:vector:install';

    protected $description = 'Create the pgvector extension and verify the connection can host it';

    public function handle(VectorStore $store): int
    {
        if ($store instanceof PgVectorStore) {
            try {
                $store->createExtension();
            } catch (Throwable $exception) {
                $this->components->warn('Could not create the extension automatically: '.$exception->getMessage());
                $this->components->bulletList([
                    'Run `CREATE EXTENSION IF NOT EXISTS vector;` as a database superuser, or',
                    'use an image that ships it, for example pgvector/pgvector:pg17.',
                ]);
            }
        }

        try {
            $store->assertSupported();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Vector store [{$store->name()}] is ready ({$store->dimensions()} dimensions).");

        return self::SUCCESS;
    }
}
