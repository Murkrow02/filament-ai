<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Murkrow\FilamentAi\Contracts\ResizableVectorStore;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Enums\DocumentStatus;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;

class VectorReindexCommand extends Command
{
    protected $signature = 'rag:vector:reindex
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Rebuild the approximate-nearest-neighbour index, resizing the vector column first when the configured dimensions changed';

    public function handle(VectorStore $store): int
    {
        $chunks = Chunk::query()->whereNotNull('embedded_at')->count();
        $configured = $store->dimensions();

        // The column is created once, by a migration, with whatever the config
        // said at that moment. Changing rag.embeddings.dimensions afterwards
        // does not touch it, and every write then fails in the database with
        // "expected N dimensions, not M" -- rebuilding the index alone cannot
        // fix that.
        $resizable = $store instanceof ResizableVectorStore ? $store : null;
        $installed = $resizable?->installedDimensions();
        $resize = $resizable !== null && $installed !== null && $installed !== $configured;

        $this->components->twoColumnDetail('driver', $store->name());
        $this->components->twoColumnDetail('embedded chunks', number_format($chunks));
        $this->components->twoColumnDetail('column width', match (true) {
            $installed === null => 'n/a',
            $resize => "<fg=yellow>{$installed}</> -> {$configured} (rag.embeddings.dimensions)",
            default => (string) $installed,
        });

        if ($resize) {
            $this->components->warn(
                "The vector column holds {$installed}-dimension vectors but rag.embeddings.dimensions is {$configured}. "
                .'Resizing discards every stored vector: vectors of different widths come from different models and are not comparable.'
            );
        }

        $question = $resize
            ? 'Discard '.number_format($chunks).' stored vectors, resize the column and rebuild the index?'
            : 'Rebuild the vector index? Searches will fall back to a sequential scan while it builds.';

        if (! $this->option('force') && ! $this->confirm($question, ! $resize)) {
            return self::SUCCESS;
        }

        // Building after a bulk load produces a better graph than incremental
        // inserts do, and is substantially faster overall.
        $this->components->task('dropping index', static function () use ($store): bool {
            $store->dropIndexes();

            return true;
        });

        if ($resize) {
            $this->components->task("resizing column to {$configured} dimensions", static function () use ($resizable, $configured): bool {
                $resizable->resize($configured);

                // Nothing is embedded any more, so coverage must say so.
                Document::query()->update(['embedded_chunk_count' => 0]);
                Document::query()
                    ->where('status', DocumentStatus::Embedded->value)
                    ->update(['status' => DocumentStatus::Chunked->value]);

                return true;
            });
        }

        $this->components->task('building index', static function () use ($store): bool {
            $store->installIndexes($store->dimensions());

            return true;
        });

        if ($resize) {
            $this->components->warn(
                'Search returns nothing until the corpus is re-embedded: php artisan rag:ingest <source> --mode=embeddings_only'
            );

            return self::SUCCESS;
        }

        $this->components->info('Done. For large corpora, raising maintenance_work_mem before this command makes the build markedly faster.');

        return self::SUCCESS;
    }
}
