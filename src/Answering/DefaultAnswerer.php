<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Answering;

use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Contracts\Answerer;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Contracts\PromptRenderer;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Contracts\TokenEstimator;
use Murkrow\FilamentAi\Data\AnswerOptions;
use Murkrow\FilamentAi\Data\AnswerResult;
use Murkrow\FilamentAi\Data\Citation;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Murkrow\FilamentAi\Data\RetrievalResult;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Data\Usage;
use Murkrow\FilamentAi\Chunking\TokenEstimatorFactory;
use Murkrow\FilamentAi\Events\QueryAnswered;
use Murkrow\FilamentAi\Models\QueryLog;
use Murkrow\FilamentAi\Models\QueryCitation;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Support\Text;

/**
 * Retrieval-augmented answering.
 *
 * The contract with the model is deliberately narrow: it may only use the
 * numbered context blocks, and it must cite them. When retrieval comes back
 * empty the model is never called at all -- an LLM handed no context will
 * answer from its parameters, which is exactly the failure mode a grounded
 * system exists to prevent.
 */
final class DefaultAnswerer implements Answerer
{
    private readonly TokenEstimator $estimator;

    public function __construct(
        private readonly Retriever $retriever,
        private readonly LanguageModel $model,
        private readonly PromptRenderer $prompts,
        private readonly SourceRegistry $sources,
        private readonly CitationParser $parser = new CitationParser,
        ?TokenEstimator $estimator = null,
    ) {
        $this->estimator = $estimator ?? (new TokenEstimatorFactory)->make(ChunkingOptions::fromConfig());
    }

    public function answer(string $question, AnswerOptions $options = new AnswerOptions): AnswerResult
    {
        $start = hrtime(true);

        $retrieval = $this->retriever->retrieve($question, $options->retrieval);
        $citations = $this->citationsFor($retrieval);

        if ($citations->isEmpty()) {
            return $this->refuse($question, $retrieval, $options, $start);
        }

        [$system, $user] = $this->prompt($question, $citations, $options);

        $result = $this->model->generate(
            $system,
            $user,
            $options->model,
            $options->temperature,
            $options->maxTokens,
        );

        $result = $this->retryIfUnmarked($result, $system, $user, $options);

        return $this->finish($question, (string) $result['text'], $citations, $retrieval, $options, $result, $start);
    }

    /**
     * @return Generator<int, string, mixed, AnswerResult>
     */
    public function stream(string $question, AnswerOptions $options = new AnswerOptions): Generator
    {
        // No retry here: deltas are already on their way to the caller by the
        // time an unmarked answer could be detected, so a second pass would
        // stream a duplicate answer rather than fix the first one.
        $start = hrtime(true);

        $retrieval = $this->retriever->retrieve($question, $options->retrieval);
        $citations = $this->citationsFor($retrieval);

        if ($citations->isEmpty()) {
            $refusal = $this->refuse($question, $retrieval, $options, $start);

            yield $refusal->answer;

            return $refusal;
        }

        [$system, $user] = $this->prompt($question, $citations, $options);

        $stream = $this->model->stream(
            $system,
            $user,
            $options->model,
            $options->temperature,
            $options->maxTokens,
        );

        foreach ($stream as $delta) {
            yield $delta;
        }

        $result = $stream->getReturn();

        return $this->finish($question, (string) $result['text'], $citations, $retrieval, $options, $result, $start);
    }

    /**
     * Number the retrieved chunks and trim the list to the context budget.
     *
     * @return Collection<int, Citation>
     */
    private function citationsFor(RetrievalResult $retrieval): Collection
    {
        $budget = (int) config('rag.answering.max_context_tokens', 6000);
        $used = 0;
        $marker = 1;

        $citations = collect();

        foreach ($retrieval->chunks as $rank => $chunk) {
            $tokens = $this->estimator->count($chunk->content);

            // Always admit the first block: a single oversized chunk truncated
            // to nothing would refuse a question the corpus can answer.
            if ($citations->isNotEmpty() && $used + $tokens > $budget) {
                break;
            }

            $used += $tokens;

            $citations->push(new Citation(
                marker: $marker++,
                rank: (int) $rank,
                chunk: $chunk,
                label: $this->labelFor($chunk),
            ));
        }

        return $citations;
    }

    private function labelFor(ScoredChunk $chunk): string
    {
        $position = $this->sources->has($chunk->sourceKey)
            ? $this->sources->get($chunk->sourceKey)->positionLabel($chunk->positionStart, $chunk->positionEnd)
            : Text::positionLabel($chunk->positionStart, $chunk->positionEnd, ':start-:end', ':start');

        $title = $chunk->documentTitle;

        return $title === null || $title === '' ? $position : "{$title} - {$position}";
    }

    /**
     * @param  Collection<int, Citation>  $citations
     * @return array{0: string, 1: string}
     */
    private function prompt(string $question, Collection $citations, AnswerOptions $options): array
    {
        $language = $options->language ?? (string) config('rag.answering.language', 'en');

        $system = $options->systemPrompt ?? $this->prompts->system(
            $language,
            $this->refusalMessage(),
            (bool) config('rag.answering.require_citations', true),
        );

        $user = $this->prompts->user(
            $question,
            $this->prompts->context($citations),
            $options->history,
        );

        return [$system, $user];
    }

    /**
     * @param  Collection<int, Citation>  $citations
     * @param  array{text: string, usage: Usage, model: string}  $result
     */
    private function finish(
        string $question,
        string $answer,
        Collection $citations,
        RetrievalResult $retrieval,
        AnswerOptions $options,
        array $result,
        float|int $start,
    ): AnswerResult {
        $answer = $this->parser->stripUnknownMarkers($answer, $citations);
        $citations = $this->parser->markUsed($answer, $citations);

        $refused = $this->looksRefused($answer, $citations);

        $usage = $result['usage']->plus(new Usage(embeddingTokens: $retrieval->embeddingTokens));

        $result = new AnswerResult(
            question: $question,
            answer: $refused ? $this->refusalMessage() : trim($answer),
            citations: $citations,
            retrieval: $retrieval,
            usage: $usage,
            refused: $refused,
            model: $result['model'],
            latencyMs: (int) round((hrtime(true) - $start) / 1_000_000),
            queryUuid: (string) Str::uuid(),
        );

        $this->log($result, $options);

        QueryAnswered::dispatch($result, $options->channel);

        return $result;
    }

    private function refuse(
        string $question,
        RetrievalResult $retrieval,
        AnswerOptions $options,
        float|int $start,
    ): AnswerResult {
        $result = new AnswerResult(
            question: $question,
            answer: $this->refusalMessage(),
            citations: collect(),
            retrieval: $retrieval,
            usage: new Usage(embeddingTokens: $retrieval->embeddingTokens),
            refused: true,
            model: null,
            latencyMs: (int) round((hrtime(true) - $start) / 1_000_000),
            queryUuid: (string) Str::uuid(),
        );

        $this->log($result, $options);

        QueryAnswered::dispatch($result, $options->channel);

        return $result;
    }

    /**
     * @param  Collection<int, Citation>  $citations
     */
    private function looksRefused(string $answer, Collection $citations): bool
    {
        if (trim($answer) === '') {
            return true;
        }

        if (! config('rag.answering.require_citations', true)) {
            return false;
        }

        // An answer with no marker at all is ungrounded by construction, even
        // when it sounds confident.
        return ! $citations->contains(static fn (Citation $c): bool => $c->used);
    }

    /**
     * A model that answered from the context but forgot the citation markers
     * produces a false refusal downstream, indistinguishable from one that
     * genuinely found nothing. Small/local models miss the marker format
     * intermittently even though the content is grounded, so one retry with
     * an explicit reminder is cheaper than that false negative -- it only
     * fires when the first pass produced real, unmarked text.
     *
     * @param  array{text: string, usage: Usage, model: string}  $result
     * @return array{text: string, usage: Usage, model: string}
     */
    private function retryIfUnmarked(array $result, string $system, string $user, AnswerOptions $options): array
    {
        if (! config('rag.answering.require_citations', true)) {
            return $result;
        }

        $text = trim((string) $result['text']);

        if ($text === '' || $text === trim($this->refusalMessage()) || $this->parser->hasAnyMarker($text)) {
            return $result;
        }

        $retry = $this->model->generate(
            $system,
            $user."\n\nYour previous answer did not include any [#n] citation markers. Rewrite it, keeping the same facts, and place a marker like [#1] immediately after every claim it comes from.",
            $options->model,
            $options->temperature,
            $options->maxTokens,
        );

        return [
            'text' => $retry['text'],
            'usage' => $result['usage']->plus($retry['usage']),
            'model' => $retry['model'],
        ];
    }

    private function refusalMessage(): string
    {
        $configured = config('rag.answering.refusal_message');

        return $configured === null || $configured === ''
            ? (string) __('rag::rag.refusal')
            : (string) $configured;
    }

    private function log(AnswerResult $result, AnswerOptions $options): void
    {
        if (! $options->log || ! config('rag.retrieval.log_queries', true)) {
            return;
        }

        $query = QueryLog::query()->create([
            'uuid' => $result->queryUuid,
            'source_keys' => $options->retrieval->sourceKeys,
            'question' => $result->question,
            'question_hash' => hash('sha256', mb_strtolower(trim($result->question))),
            'embedding_model' => (string) config('rag.embeddings.model'),
            'llm_model' => $result->model,
            'filters' => $options->retrieval->toArray(),
            'top_k' => $result->citations->count(),
            'retrieved_count' => $result->retrieval->chunks->count(),
            'top_score' => $result->retrieval->topScore(),
            'min_score' => $options->retrieval->minScore,
            'answer' => $result->answer,
            'refused' => $result->refused,
            'prompt_tokens' => $result->usage->promptTokens,
            'completion_tokens' => $result->usage->completionTokens,
            'embedding_tokens' => $result->usage->embeddingTokens,
            'cost_micros' => $result->usage->costMicros,
            'latency_ms' => $result->latencyMs,
            'retrieval_ms' => $result->retrieval->totalMs(),
            'channel' => $options->channel->value,
            'user_id' => $options->userId === null ? null : (string) $options->userId,
            'conversation_id' => $options->conversationId,
            'turn' => $options->turn ?? 0,
        ]);

        $rows = [];

        foreach ($result->citations as $citation) {
            $rows[] = [
                'query_id' => $query->id,
                'chunk_id' => $citation->chunk->chunkId,
                'document_id' => $citation->chunk->documentId,
                'marker' => $citation->marker,
                'score' => $citation->chunk->score,
                'rank' => $citation->rank,
                'position_start' => $citation->chunk->positionStart,
                'position_end' => $citation->chunk->positionEnd,
                'used' => $citation->used,
                'snippet' => Text::snippet($citation->chunk->content, 300),
            ];
        }

        if ($rows !== []) {
            QueryCitation::query()->insert($rows);
        }
    }
}
