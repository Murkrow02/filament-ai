<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Data\ChunkDraft;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Reconciles a freshly chunked document against what is already stored.
 *
 * This is what makes re-ingestion cheap. Chunks are matched by content hash,
 * so a document whose first page changed keeps the vectors for every other
 * page: only genuinely new text is sent to the embedding API. On a corpus of
 * hundreds of thousands of chunks that is the difference between a re-run
 * costing cents and costing the full re-index.
 *
 * @phpstan-type Diff array{created: int, reused: int, deleted: int}
 */
final class ChunkDiffer
{
    /**
     * Ordinals are temporarily shifted by this much so that re-numbering
     * cannot collide with the (document_id, ordinal) unique index mid-update.
     */
    private const ORDINAL_PARKING_OFFSET = 1_000_000_000;

    public function __construct(
        private readonly VectorStore $store,
    ) {}

    /**
     * @param  iterable<int, ChunkDraft>  $drafts
     * @return array{created: int, reused: int, deleted: int, tokens: int}
     */
    public function apply(Document $document, iterable $drafts, string $embeddingModel, int $dimensions): array
    {
        $connection = DB::connection(Tables::connection());

        return $connection->transaction(function () use ($document, $drafts, $embeddingModel, $dimensions): array {
            /** @var array<string, array<int, int>> $available hash => stack of chunk ids */
            $available = [];

            foreach (Chunk::query()->where('document_id', $document->id)->get(['id', 'content_hash']) as $existing) {
                $available[(string) $existing->content_hash][] = (int) $existing->id;
            }

            $reusedIds = [];
            $inserts = [];
            $updates = [];
            $tokens = 0;
            $created = 0;
            $reused = 0;

            foreach ($drafts as $draft) {
                $tokens += $draft->tokenCount;

                $candidate = empty($available[$draft->contentHash])
                    ? null
                    : array_shift($available[$draft->contentHash]);

                if ($candidate !== null) {
                    // Same text, same embedding input: the vector still stands.
                    $reusedIds[] = $candidate;
                    $updates[] = [$candidate, $draft];
                    $reused++;

                    continue;
                }

                $inserts[] = $this->insertRow($document, $draft, $embeddingModel, $dimensions);
                $created++;
            }

            $orphans = [];

            foreach ($available as $ids) {
                foreach ($ids as $id) {
                    $orphans[] = $id;
                }
            }

            if ($orphans !== []) {
                $this->store->forget($orphans);
                Chunk::query()->whereIn('id', $orphans)->delete();
            }

            // Park surviving rows out of the way before renumbering them.
            if ($reusedIds !== []) {
                Chunk::query()
                    ->whereIn('id', $reusedIds)
                    ->update(['ordinal' => DB::raw('ordinal + '.self::ORDINAL_PARKING_OFFSET)]);

                foreach ($updates as [$id, $draft]) {
                    Chunk::query()->whereKey($id)->update([
                        'ordinal' => $draft->ordinal,
                        'position_start' => $draft->positionStart,
                        'position_end' => $draft->positionEnd,
                        'char_start' => $draft->charStart,
                        'char_end' => $draft->charEnd,
                        'token_count' => $draft->tokenCount,
                        'updated_at' => now(),
                    ]);
                }
            }

            foreach (array_chunk($inserts, 500) as $batch) {
                Chunk::query()->insert($batch);
            }

            return [
                'created' => $created,
                'reused' => $reused,
                'deleted' => count($orphans),
                'tokens' => $tokens,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function insertRow(Document $document, ChunkDraft $draft, string $embeddingModel, int $dimensions): array
    {
        return [
            'document_id' => $document->id,
            'source_key' => $document->source_key,
            'ordinal' => $draft->ordinal,
            'position_start' => $draft->positionStart,
            'position_end' => $draft->positionEnd,
            'char_start' => $draft->charStart,
            'char_end' => $draft->charEnd,
            'content' => $draft->text,
            'content_hash' => $draft->contentHash,
            'token_count' => min($draft->tokenCount, 65535),
            // Left NULL: the embedding jobs claim rows by this column.
            'embedding_model' => null,
            'embedding_dimensions' => null,
            'embedded_at' => null,
            // The header is stored, not recomputed: rebuilding the embedding
            // input later must not depend on the run's chunking parameters.
            'metadata' => json_encode(array_filter(
                $draft->metadata + ['header' => $draft->header],
                static fn (mixed $v): bool => $v !== null,
            )) ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
