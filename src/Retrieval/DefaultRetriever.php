<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\RetrievalResult;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Retrieval\Lexical\LexicalSearchManager;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * The retrieval pipeline.
 *
 * Over-fetch (fetch_k) -> optional lexical fusion -> score floor -> de-dupe ->
 * MMR -> optional neighbour expansion -> top_k.
 *
 * The over-fetch is what makes the later stages possible: de-duplication and
 * MMR both remove candidates, so asking the store for exactly top_k would leave
 * the caller short.
 */
final class DefaultRetriever implements Retriever
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly VectorStore $store,
        private readonly LexicalSearchManager $lexical,
        private readonly SourceRegistry $sources,
        private readonly Deduplicator $deduplicator = new Deduplicator,
        private readonly Mmr $mmr = new Mmr,
        private readonly ReciprocalRankFusion $fusion = new ReciprocalRankFusion,
        private readonly NeighborExpander $neighbors = new NeighborExpander,
    ) {}

    public function retrieve(string $question, RetrievalOptions $options = new RetrievalOptions): RetrievalResult
    {
        $timings = [];

        $topK = $options->topK ?? (int) config('filament-ai.retrieval.top_k', 8);
        $fetchK = max($topK, $options->fetchK ?? (int) config('filament-ai.retrieval.fetch_k', 40));
        $minScore = $options->minScore ?? (float) config('filament-ai.retrieval.min_score', 0.0);
        $useMmr = $options->mmr ?? (bool) config('filament-ai.retrieval.mmr.enabled', true);
        $lambda = $options->mmrLambda ?? (float) config('filament-ai.retrieval.mmr.lambda', 0.6);
        $dedupeThreshold = (float) config('filament-ai.retrieval.dedupe_threshold', 0.97);
        $expand = $options->expandNeighbors ?? (int) config('filament-ai.retrieval.expand_neighbors', 0);

        $start = hrtime(true);
        $vector = $this->embedQuery($question);
        $timings['embed_ms'] = $this->msSince($start);

        if ($vector === []) {
            return new RetrievalResult(collect(), $question, 0, $timings);
        }

        $query = new VectorQuery(
            vector: $vector,
            limit: $fetchK,
            sourceKeys: $options->sourceKeys,
            documentIds: $options->documentIds,
            externalIds: $options->externalIds,
            positionFrom: $options->positionFrom,
            positionTo: $options->positionTo,
            minScore: $minScore > 0 ? $minScore : null,
            constrain: $options->constrain,
        );

        $start = hrtime(true);
        $hits = $this->store->search($query);
        $timings['search_ms'] = $this->msSince($start);

        $examined = $hits->count();

        $hits = $this->fuseLexical($question, $query, $hits, $options, $timings);

        if ($minScore > 0) {
            $hits = $hits->filter(static fn (ScoredChunk $c): bool => $c->score >= $minScore)->values();
        }

        // MMR and near-duplicate collapsing both compare candidates against
        // each other, which needs the vectors the store did not return.
        $needsVectors = $useMmr || $dedupeThreshold < 1.0 || $options->withVectors;

        if ($needsVectors && $hits->isNotEmpty()) {
            $start = hrtime(true);
            $hits = $this->attachVectors($hits);
            $timings['vectors_ms'] = $this->msSince($start);
        }

        $start = hrtime(true);
        $hits = $this->deduplicator->dedupe($hits, $dedupeThreshold);

        if ($useMmr) {
            $hits = $this->mmr->rerank($hits, $vector, $lambda, $topK);
        } else {
            $hits = $hits->take($topK)->values();
        }

        if ($expand > 0) {
            $hits = $this->neighbors->expand($hits, $expand);
        }

        $timings['rerank_ms'] = $this->msSince($start);

        $hits = $this->attachUrls($hits);

        if (! $options->withVectors) {
            // Vectors are heavy and of no use to callers; drop them before the
            // result escapes into a view, a queue payload or an MCP response.
            $hits = $hits->map(static fn (ScoredChunk $c): ScoredChunk => $c->withVector(null))->values();
        }

        return new RetrievalResult(
            chunks: $hits->values(),
            query: $question,
            embeddingTokens: 0,
            timings: $timings,
            candidatesExamined: $examined,
        );
    }

    /**
     * @return array<int, float>
     */
    private function embedQuery(string $question): array
    {
        $question = trim($question);

        if ($question === '') {
            return [];
        }

        if (! config('filament-ai.embeddings.cache_queries', true)) {
            return $this->embeddings->embedQuery($question);
        }

        $key = 'ai:q:'.sha1($this->embeddings->model().'|'.$question);

        /** @var array<int, float> */
        return Cache::remember(
            $key,
            (int) config('filament-ai.embeddings.query_cache_ttl', 3600),
            fn (): array => $this->embeddings->embedQuery($question),
        );
    }

    /**
     * @param  Collection<int, ScoredChunk>  $hits
     * @param  array<string, int>  $timings
     * @return Collection<int, ScoredChunk>
     */
    private function fuseLexical(
        string $question,
        VectorQuery $query,
        Collection $hits,
        RetrievalOptions $options,
        array &$timings,
    ): Collection {
        $driver = $options->hybridDriver ?? config('filament-ai.retrieval.hybrid.driver');

        if ($driver === null || $driver === '' || $driver === 'null') {
            return $hits;
        }

        $search = $this->lexical->driver((string) $driver);

        if (! $search->isAvailable()) {
            return $hits;
        }

        $start = hrtime(true);

        $ids = $search->candidates(
            $question,
            $query,
            (int) config('filament-ai.retrieval.hybrid.candidates', 100),
        );

        $fused = $this->fusion->fuse(
            $hits,
            $ids,
            (int) config('filament-ai.retrieval.hybrid.rrf_k', 60),
            (float) config('filament-ai.retrieval.hybrid.weight', 0.35),
        );

        $timings['lexical_ms'] = $this->msSince($start);

        return $fused;
    }

    /**
     * @param  Collection<int, ScoredChunk>  $hits
     * @return Collection<int, ScoredChunk>
     */
    private function attachVectors(Collection $hits): Collection
    {
        $vectors = $this->store->read($hits->pluck('chunkId')->all());

        return $hits->map(
            static fn (ScoredChunk $c): ScoredChunk => $c->withVector($vectors[$c->chunkId] ?? null),
        );
    }

    /**
     * Ask each source for a deep link, so a citation can point at the page in
     * the host application rather than at nothing.
     *
     * @param  Collection<int, ScoredChunk>  $hits
     * @return Collection<int, ScoredChunk>
     */
    private function attachUrls(Collection $hits): Collection
    {
        if ($hits->isEmpty()) {
            return $hits;
        }

        $documents = Document::query()
            ->whereIn('id', $hits->pluck('documentId')->unique()->all())
            ->get()
            ->keyBy('id');

        return $hits->map(function (ScoredChunk $chunk) use ($documents): ScoredChunk {
            if (! $this->sources->has($chunk->sourceKey)) {
                return $chunk;
            }

            /** @var Document|null $document */
            $document = $documents->get($chunk->documentId);

            if ($document === null) {
                return $chunk;
            }

            // Not persisted -- just a typed carrier so a source's url() can
            // read position_start/position_end without a second query per hit.
            $chunkModel = new Chunk([
                'id' => $chunk->chunkId,
                'document_id' => $chunk->documentId,
                'source_key' => $chunk->sourceKey,
                'ordinal' => $chunk->ordinal,
                'position_start' => $chunk->positionStart,
                'position_end' => $chunk->positionEnd,
                'content_hash' => $chunk->contentHash,
                'metadata' => $chunk->metadata,
            ]);

            return $chunk->withUrl($this->sources->get($chunk->sourceKey)->url($document, $chunkModel));
        });
    }

    private function msSince(float|int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
