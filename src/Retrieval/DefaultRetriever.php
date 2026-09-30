<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Contracts\LexicalSearch;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\RetrievalResult;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Retrieval\Lexical\LexicalSearchManager;
use Murkrow\FilamentAi\Reranking\RerankerManager;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Support\Excerpt;
use Throwable;

/**
 * The retrieval pipeline.
 *
 * Optional query expansion -> per query: vector search (score floor applied
 * there) and optional lexical search -> rank fusion -> optional cross
 * reranking -> de-dupe -> MMR -> optional neighbour expansion -> top_k.
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
        private readonly ?RerankerManager $rerankerManager = null,
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

        $queries = [trim($question)];

        if ($this->shouldExpand($question, $options)) {
            $start = hrtime(true);
            $queries = [...$queries, ...app(QueryExpander::class)->expand($question)];
            $timings['expand_ms'] = $this->msSince($start);
        }

        $start = hrtime(true);
        $vectors = array_map(fn (string $q): array => $this->embedQuery($q), $queries);
        $timings['embed_ms'] = $this->msSince($start);

        $vector = $vectors[0];

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

        $lexical = $this->lexicalSearch($options);
        $rewriteWeight = (float) config('filament-ai.retrieval.expansion.weight', 0.7);
        $lexicalWeight = (float) config('filament-ai.retrieval.hybrid.weight', 0.35);

        /** @var array<int, ScoredChunk> $pool best cosine seen for each chunk */
        $pool = [];
        $lists = [];
        $examined = 0;
        $timings['search_ms'] = 0;

        foreach ($queries as $index => $text) {
            if ($vectors[$index] === []) {
                continue;
            }

            $weight = $index === 0 ? 1.0 : $rewriteWeight;

            $start = hrtime(true);
            $hits = $this->store->search(new VectorQuery(
                $vectors[$index], $query->limit, $query->sourceKeys, $query->documentIds, $query->externalIds,
                $query->positionFrom, $query->positionTo, $query->minScore, $query->constrain,
            ));
            $timings['search_ms'] += $this->msSince($start);
            $examined += $hits->count();

            foreach ($hits as $hit) {
                if (! isset($pool[$hit->chunkId]) || $pool[$hit->chunkId]->score < $hit->score) {
                    $pool[$hit->chunkId] = $hit;
                }
            }

            $lists[] = ['ids' => $hits->pluck('chunkId')->all(), 'weight' => $weight * (1 - ($lexical === null ? 0.0 : $lexicalWeight))];

            if ($lexical !== null) {
                $start = hrtime(true);
                $ids = $lexical->candidates($text, $query, (int) config('filament-ai.retrieval.hybrid.candidates', 100));
                $timings['lexical_ms'] = ($timings['lexical_ms'] ?? 0) + $this->msSince($start);

                $lists[] = ['ids' => $ids, 'weight' => $weight * $lexicalWeight];
            }
        }

        // One list is the plain vector search: its cosine order stands, and so
        // does the score floor the store already applied.
        if (count($lists) === 1) {
            $hits = collect(array_values($pool));
            $relevance = null;
        } else {
            $fused = $this->fusion->fuse($lists, (int) config('filament-ai.retrieval.hybrid.rrf_k', 60));
            $fused = array_slice($fused, 0, $fetchK, true);

            $start = hrtime(true);
            $pool += $this->hydrate(array_keys(array_diff_key($fused, $pool)), $query);
            $timings['hydrate_ms'] = $this->msSince($start);

            $hits = collect(array_keys($fused))
                ->filter(static fn (int $id): bool => isset($pool[$id]))
                ->map(static fn (int $id): ScoredChunk => $pool[$id])
                ->values();

            // Fused scores are ~0.01 and mean nothing on their own: rescale to
            // 0-1 so MMR can weigh them against cosine redundancy.
            $max = max($fused ?: [1.0]);
            $relevance = array_map(static fn (float $s): float => $max > 0 ? $s / $max : 0.0, $fused);
        }

        $reranked = $this->rerank($question, implode(' ', $queries), $hits, $options, $timings);

        if ($reranked !== null) {
            [$hits, $relevance] = $reranked;
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
            $hits = $this->mmr->rerank($hits, $vector, $lambda, $topK, $relevance);
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

    private function shouldExpand(string $question, RetrievalOptions $options): bool
    {
        if (! ($options->expand ?? (bool) config('filament-ai.retrieval.expansion.enabled', false))) {
            return false;
        }

        // A quoted query asks for those exact words; rewriting it would not help.
        return preg_match('/^\s*["\x{201C}\x{00AB}].*["\x{201D}\x{00BB}]\s*$/us', $question) !== 1;
    }

    private function lexicalSearch(RetrievalOptions $options): ?LexicalSearch
    {
        $driver = $options->hybridDriver ?? config('filament-ai.retrieval.hybrid.driver');

        if ($driver === null || $driver === '' || $driver === 'null' || $driver === 'none') {
            return null;
        }

        $search = $this->lexical->driver((string) $driver);

        return $search->isAvailable() ? $search : null;
    }

    /**
     * Load the chunks only the lexical leg or a rewrite found, scored by cosine
     * against the question itself. The store applies the caller's filters and
     * scope again, so nothing the caller may not see gets in this way.
     *
     * @param  array<int, int>  $chunkIds
     * @return array<int, ScoredChunk>
     */
    private function hydrate(array $chunkIds, VectorQuery $query): array
    {
        if ($chunkIds === []) {
            return [];
        }

        $hits = $this->store->search(new VectorQuery(
            $query->vector, count($chunkIds), $query->sourceKeys, $query->documentIds, $query->externalIds,
            $query->positionFrom, $query->positionTo, null, $query->constrain, $chunkIds,
        ));

        return $hits->keyBy('chunkId')->all();
    }

    /**
     * Second-stage ranking of the head of the list. Returns null when no
     * reranker is configured, it failed, or it told nothing apart, so the
     * fused order stands.
     *
     * @param  string  $terms  the question and its rewrites, to pick each passage's excerpt
     * @param  Collection<int, ScoredChunk>  $hits
     * @param  array<string, int>  $timings
     * @return array{0: Collection<int, ScoredChunk>, 1: array<int, float>}|null
     */
    private function rerank(string $question, string $terms, Collection $hits, RetrievalOptions $options, array &$timings): ?array
    {
        $driver = $options->rerankDriver ?? config('filament-ai.retrieval.rerank.driver');

        if ($hits->count() < 2 || $driver === null || $driver === '' || $driver === 'null' || $driver === 'none') {
            return null;
        }

        $start = hrtime(true);

        try {
            $reranker = ($this->rerankerManager ?? app(RerankerManager::class))->driver((string) $driver);

            if (! $reranker->isAvailable()) {
                return null;
            }

            $head = $hits->take(max(2, (int) config('filament-ai.retrieval.rerank.candidates', 30)))->values();
            $scores = $reranker->score($question, $head->map(fn (ScoredChunk $c): string => $this->rerankText($c, $terms))->all());
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            $timings['cross_rerank_ms'] = $this->msSince($start);
        }

        // The same grade for every passage (all 0, typically) orders nothing
        // and would only overwrite the scores with a constant.
        if (count($scores) !== $head->count() || max($scores) - min($scores) < 1e-6) {
            return null;
        }

        $relevance = [];

        foreach ($head as $index => $chunk) {
            $relevance[$chunk->chunkId] = $scores[$index];
        }

        $ranked = $head
            ->sortByDesc(static fn (ScoredChunk $c): float => $relevance[$c->chunkId])
            ->map(static fn (ScoredChunk $c): ScoredChunk => $c->withScore($relevance[$c->chunkId]))
            ->values();

        return [$ranked, $relevance];
    }

    /**
     * What the reranker reads of a passage: the title and the window where
     * the question's words (and its rewrites') cluster, not the start of
     * the chunk, where the answer often is not.
     */
    private function rerankText(ScoredChunk $chunk, string $terms): string
    {
        $title = $chunk->documentTitle === null ? '' : $chunk->documentTitle."\n";

        return $title.Excerpt::around($chunk->content, $terms, (int) config('filament-ai.retrieval.rerank.max_chars', 1200));
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
