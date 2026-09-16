<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Murkrow\FilamentAi\Data\DocumentDraft;
use Murkrow\FilamentAi\Data\IngestionEstimate;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Enums\RunItemStatus;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Models\IngestionRunItem;
use Illuminate\Support\Str;

/**
 * Creates a run and its work list.
 *
 * Everything variable is frozen into the run row at this point -- chunking
 * parameters, embedding model, dimensions, vector driver -- so that editing
 * config while a run is in flight cannot produce a corpus chunked two
 * different ways.
 */
final class IngestionPlanner
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly VectorStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $chunkingOverrides
     */
    public function plan(
        KnowledgeSource $source,
        array $filters = [],
        IngestionMode $mode = IngestionMode::Incremental,
        array $chunkingOverrides = [],
        int|string|null $createdBy = null,
    ): IngestionRun {
        $options = ChunkingOptions::fromConfig(
            array_replace_recursive($source->chunkingOverrides()->toArray(), $chunkingOverrides),
        );

        $run = IngestionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'source_key' => $source->key(),
            'status' => RunStatus::Queued,
            'mode' => $mode,
            'filters' => $filters === [] ? null : $filters,
            'chunking_params' => $options->toArray(),
            'embedding_model' => $this->embeddings->model(),
            'embedding_dimensions' => $this->embeddings->dimensions(),
            'vector_driver' => $this->store->name(),
            'documents_total' => 0,
        ]);

        $total = 0;
        $buffer = [];

        foreach ($source->documents($filters) as $draft) {
            /** @var DocumentDraft $draft */
            $buffer[] = [
                'run_id' => $run->id,
                'external_id' => $draft->externalId,
                'status' => RunItemStatus::Pending->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $total++;

            if (count($buffer) >= 1000) {
                IngestionRunItem::query()->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            IngestionRunItem::query()->insert($buffer);
        }

        $run->forceFill(['documents_total' => $total])->save();

        return $run;
    }

    /**
     * Pre-flight estimate for the control panel: how much work, and how much
     * it will cost, before anyone commits to it.
     *
     * @param  array<string, mixed>  $filters
     */
    public function estimate(KnowledgeSource $source, array $filters = [], int $sampleSize = 5): IngestionEstimate
    {
        $documents = $source->countDocuments($filters);

        if ($documents === 0) {
            return new IngestionEstimate(0, 0, 0, 0, 0);
        }

        $options = ChunkingOptions::fromConfig($source->chunkingOverrides()->toArray());

        $sampleDocuments = 0;
        $sampleSegments = 0;
        $sampleChars = 0;

        foreach ($source->documents($filters)->take($sampleSize) as $draft) {
            $sampleDocuments++;

            foreach ($source->segments($draft->externalId) as $segment) {
                $sampleSegments++;
                $sampleChars += mb_strlen($segment->text);
            }
        }

        if ($sampleDocuments === 0 || $sampleChars === 0) {
            return new IngestionEstimate($documents, 0, 0, 0, 0);
        }

        $charsPerDocument = $sampleChars / $sampleDocuments;
        $segmentsPerDocument = $sampleSegments / $sampleDocuments;

        $totalChars = $charsPerDocument * $documents;
        $tokens = (int) round($totalChars / $options->charsPerToken);

        // Overlap means the embedded token count exceeds the source token
        // count; the effective stride is target minus overlap.
        $stride = max(1, $options->targetTokens - $options->overlapTokens);
        $chunks = (int) ceil($tokens / $stride);
        $embeddedTokens = $chunks * $options->targetTokens;

        return new IngestionEstimate(
            documents: $documents,
            segments: (int) round($segmentsPerDocument * $documents),
            tokens: $embeddedTokens,
            chunks: $chunks,
            costMicros: CostCalculator::embeddingMicros($this->embeddings->model(), $embeddedTokens),
            sampled: true,
        );
    }
}
