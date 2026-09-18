<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

use Murkrow\FilamentAi\Chunking\Normalizers\NormalizerPipeline;
use Murkrow\FilamentAi\Contracts\Chunker;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Murkrow\FilamentAi\Data\DocumentDraft;
use Murkrow\FilamentAi\Data\DocumentIngestionResult;
use Murkrow\FilamentAi\Data\Segment;
use Murkrow\FilamentAi\Enums\DocumentStatus;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Events\DocumentIngested;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;

/**
 * Chunks and reconciles a single document.
 *
 * Usable both from the queue and synchronously, which is what makes
 * `ai:ingest --sync` a real end-to-end path rather than a second
 * implementation that can drift from the queued one.
 */
final class DocumentIngestor
{
    public function __construct(
        private readonly Chunker $chunker,
        private readonly ChunkDiffer $differ,
    ) {}

    public function ingest(
        KnowledgeSource $source,
        DocumentDraft $draft,
        ChunkingOptions $options,
        IngestionMode $mode,
        string $embeddingModel,
        int $dimensions,
    ): DocumentIngestionResult {
        $start = hrtime(true);

        $document = $this->upsertDocument($draft);

        $paramsChecksum = $options->checksum($embeddingModel, $dimensions);

        // Stream the segments once, hashing as we go: a 2000-page document is
        // never materialised, and the checksum costs nothing extra.
        $segments = [];
        $hash = hash_init('sha256');
        $normalizer = NormalizerPipeline::fromClasses($options->normalizers);
        $segmentCount = 0;

        foreach ($source->segments($draft->externalId) as $segment) {
            $segmentCount++;
            $normalized = $normalizer->normalize($segment->text);
            hash_update($hash, $segment->position.':'.$normalized."\n");
            $segments[] = new Segment($segment->position, $segment->text, $segment->metadata);
        }

        $contentChecksum = hash_final($hash);

        if ($this->canSkip($document, $mode, $contentChecksum, $paramsChecksum)) {
            $document->forceFill(['last_ingested_at' => now()])->save();

            return new DocumentIngestionResult(
                document: $document,
                skipped: true,
                durationMs: $this->msSince($start),
            );
        }

        $diff = $this->differ->apply(
            $document,
            $this->chunker->chunk(
                $segments,
                $options,
                $draft->title,
                fn (int $start, int $end): string => $source->positionLabel($start, $end),
            ),
            $embeddingModel,
            $dimensions,
        );

        $chunkCount = Chunk::query()->where('document_id', $document->id)->count();
        $embeddedCount = Chunk::query()
            ->where('document_id', $document->id)
            ->whereNotNull('embedded_at')
            ->count();

        $document->forceFill([
            'segment_count' => $segmentCount,
            'chunk_count' => $chunkCount,
            'embedded_chunk_count' => $embeddedCount,
            'token_count' => $diff['tokens'],
            'content_checksum' => $contentChecksum,
            'params_checksum' => $paramsChecksum,
            'status' => $embeddedCount >= $chunkCount && $chunkCount > 0
                ? DocumentStatus::Embedded
                : DocumentStatus::Chunked,
            'last_ingested_at' => now(),
            'last_error' => null,
        ])->save();

        DocumentIngested::dispatch($document, $diff['created'], $diff['reused'], $diff['deleted']);

        return new DocumentIngestionResult(
            document: $document,
            chunksCreated: $diff['created'],
            chunksReused: $diff['reused'],
            chunksDeleted: $diff['deleted'],
            tokens: $diff['tokens'],
            durationMs: $this->msSince($start),
        );
    }

    private function upsertDocument(DocumentDraft $draft): Document
    {
        /** @var Document $document */
        $document = Document::query()->firstOrNew([
            'source_key' => $draft->sourceKey,
            'external_id' => $draft->externalId,
        ]);

        $document->title = $draft->title;
        $document->metadata = $draft->metadata === [] ? null : $draft->metadata;

        if (! $document->exists) {
            $document->status = DocumentStatus::Pending;
        }

        $document->save();

        return $document;
    }

    /**
     * Incremental runs skip a document only when the source text, the chunking
     * parameters and the embedding model are all unchanged AND nothing is left
     * waiting for a vector -- otherwise a run interrupted halfway would never
     * finish the job.
     */
    private function canSkip(
        Document $document,
        IngestionMode $mode,
        string $contentChecksum,
        string $paramsChecksum,
    ): bool {
        if ($mode !== IngestionMode::Incremental) {
            return false;
        }

        if ($document->content_checksum !== $contentChecksum) {
            return false;
        }

        if ($document->params_checksum !== $paramsChecksum) {
            return false;
        }

        return $document->chunk_count > 0
            && $document->embedded_chunk_count >= $document->chunk_count;
    }

    private function msSince(float|int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
