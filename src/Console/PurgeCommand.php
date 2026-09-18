<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;

class PurgeCommand extends Command
{
    protected $signature = 'ai:purge
                            {source? : Limit the purge to one knowledge source}
                            {--embeddings-only : Keep the chunks, drop only their vectors}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete indexed documents, or just their embeddings';

    public function handle(VectorStore $store): int
    {
        $source = $this->argument('source');

        $documents = Document::query()
            ->when($source !== null, fn ($q) => $q->where('source_key', $source))
            ->count();

        $chunks = Chunk::query()
            ->when($source !== null, fn ($q) => $q->where('source_key', $source))
            ->count();

        if ($documents === 0 && $chunks === 0) {
            $this->components->info('Nothing to purge.');

            return self::SUCCESS;
        }

        $what = $this->option('embeddings-only')
            ? "the vectors of {$chunks} chunks"
            : "{$documents} documents and {$chunks} chunks";

        $scope = $source === null ? 'every source' : "source [{$source}]";

        if (! $this->option('force') && ! $this->confirm("Permanently delete {$what} from {$scope}?", false)) {
            return self::SUCCESS;
        }

        if ($this->option('embeddings-only')) {
            Chunk::query()
                ->when($source !== null, fn ($q) => $q->where('source_key', $source))
                ->orderBy('id')
                ->chunkById(1000, static function ($rows) use ($store): void {
                    $store->forget($rows->pluck('id')->all());
                });

            Document::query()
                ->when($source !== null, fn ($q) => $q->where('source_key', $source))
                ->update(['embedded_chunk_count' => 0, 'status' => 'chunked']);

            $this->components->info("Dropped vectors for {$chunks} chunks. Re-embed with `ai:ingest --mode=embeddings_only`.");

            return self::SUCCESS;
        }

        // Chunks and citations cascade from the document row.
        Document::query()
            ->when($source !== null, fn ($q) => $q->where('source_key', $source))
            ->delete();

        $this->components->info("Deleted {$documents} documents and {$chunks} chunks.");

        return self::SUCCESS;
    }
}
