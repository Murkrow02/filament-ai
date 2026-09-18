<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Jobs\Concerns\InteractsWithRagQueue;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * Removes indexed documents whose host record no longer exists.
 *
 * Ingestion cannot notice a deletion -- it only ever walks what the source
 * still returns -- so without this a deleted record keeps answering questions
 * indefinitely. Worth scheduling nightly on corpora that get pruned.
 */
class PruneOrphanChunksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithRagQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ?string $sourceKey = null)
    {
        $this->configureRagQueue();
    }

    public function handle(SourceRegistry $sources): void
    {
        $keys = $this->sourceKey === null ? $sources->keys() : [$this->sourceKey];

        foreach ($keys as $key) {
            if (! $sources->has($key)) {
                continue;
            }

            $source = $sources->get($key);

            Document::query()
                ->where('source_key', $key)
                ->orderBy('id')
                ->chunkById(500, function ($documents) use ($source): void {
                    foreach ($documents as $document) {
                        $this->pruneIfMissing($source, $document);
                    }
                });
        }
    }

    private function pruneIfMissing(KnowledgeSource $source, Document $document): void
    {
        if ($source->findDocument($document->external_id) !== null) {
            return;
        }

        // Chunks and citations cascade; the vector lives on the chunk row.
        $document->delete();
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return array_filter(['filament-ai', 'ai:prune', $this->sourceKey]);
    }
}
