<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Embeds a group of chunks and writes the vectors back.
 *
 * A group is `rag.queue.chunks_per_job` chunks; it is sent to the provider in
 * calls of at most `maxBatchSize()` texts (`rag.embeddings.batch_size`). The
 * split matters for a self-hosted embedder: Ollama serves one request at a
 * time, so the size of each call is how long a search query can wait behind
 * an ingestion that is running.
 *
 * Shared by the queued job and by `ai:ingest --sync` so the two paths cannot
 * drift. Idempotent by design: it re-reads the rows and skips anything already
 * embedded, which makes a retried job safe rather than a double charge.
 *
 * @phpstan-type EmbedResult array{embedded: int, tokens: int, cost_micros: int}
 */
final class ChunkEmbedder
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly VectorStore $store,
    ) {}

    /**
     * @param  array<int, int>  $chunkIds
     * @return array{embedded: int, tokens: int, cost_micros: int}
     */
    public function embed(array $chunkIds): array
    {
        if ($chunkIds === []) {
            return ['embedded' => 0, 'tokens' => 0, 'cost_micros' => 0];
        }

        $model = $this->embeddings->model();
        $dimensions = $this->embeddings->dimensions();

        /** @var \Illuminate\Database\Eloquent\Collection<int, Chunk> $chunks */
        $chunks = Chunk::query()
            ->whereIn('id', $chunkIds)
            ->where(function ($query) use ($model, $dimensions): void {
                $query->whereNull('embedded_at')
                    ->orWhere('embedding_model', '!=', $model)
                    ->orWhere('embedding_dimensions', '!=', $dimensions);
            })
            ->get();

        if ($chunks->isEmpty()) {
            return ['embedded' => 0, 'tokens' => 0, 'cost_micros' => 0];
        }

        $pending = [];

        foreach ($chunks as $chunk) {
            $pending[] = ['id' => (int) $chunk->id, 'input' => EmbeddingInput::for($chunk)];
        }

        $perCall = max(1, $this->embeddings->maxBatchSize());
        $vectors = [];
        $tokens = 0;
        $costMicros = 0;

        foreach (array_chunk($pending, $perCall) as $slice) {
            $batch = $this->embeddings->embedBatch(array_column($slice, 'input'));

            foreach ($batch->vectors as $index => $vector) {
                if (! isset($slice[$index])) {
                    continue;
                }

                $vectors[] = ['id' => $slice[$index]['id'], 'vector' => $vector];
            }

            $tokens += $batch->tokens;
            $costMicros += CostCalculator::embeddingMicros($batch->model, $batch->tokens);
        }

        // One write for the whole group, after every call has succeeded. Writing
        // slice by slice would leave a failed group half-embedded: the retry
        // skips the saved rows, so the run's progress would never count them.
        // Re-embedding a few chunks on a retry is the cheaper price.
        $this->store->upsert($vectors, $this->embeddings->model(), $this->embeddings->dimensions());

        $this->refreshDocumentCounters($chunks->pluck('document_id')->unique()->all());

        return [
            'embedded' => count($vectors),
            'tokens' => $tokens,
            'cost_micros' => $costMicros,
        ];
    }

    /**
     * Recompute embedded_chunk_count in SQL so the dashboard's coverage figure
     * stays correct no matter how many workers touched the document.
     *
     * @param  array<int, int>  $documentIds
     */
    private function refreshDocumentCounters(array $documentIds): void
    {
        if ($documentIds === []) {
            return;
        }

        $documents = Tables::documents();
        $chunks = Tables::chunks();

        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));

        DB::connection(Tables::connection())->update(
            <<<SQL
                UPDATE {$documents} AS d
                SET chunk_count = (
                        SELECT COUNT(*) FROM {$chunks} c WHERE c.document_id = d.id
                    ),
                    embedded_chunk_count = (
                        SELECT COUNT(*) FROM {$chunks} c
                        WHERE c.document_id = d.id AND c.embedded_at IS NOT NULL
                    )
                WHERE d.id IN ({$placeholders})
                SQL,
            array_map(intval(...), array_values($documentIds)),
        );

        Document::query()
            ->whereIn('id', $documentIds)
            ->whereColumn('embedded_chunk_count', '>=', 'chunk_count')
            ->where('chunk_count', '>', 0)
            ->update(['status' => 'embedded']);
    }
}
