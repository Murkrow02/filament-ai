<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Chat\ChatPayload;
use Murkrow\FilamentAi\Contracts\Answerer;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Data\AnswerOptions;
use Murkrow\FilamentAi\Data\AnswerResult;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Enums\QueryChannel;
use Murkrow\FilamentAi\Http\Concerns\InteractsWithConversations;
use Murkrow\FilamentAi\Http\Concerns\StreamsServerSentEvents;
use Murkrow\FilamentAi\Http\Requests\AskRequest;
use Murkrow\FilamentAi\Models\Conversation;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Asking a question, streamed or not.
 *
 * The pipeline itself is untouched: this resolves the thread, hands the
 * question to the Answerer the CLI and MCP already use, and translates the
 * result into what the page is allowed to render.
 */
class AskController
{
    use InteractsWithConversations;
    use StreamsServerSentEvents;

    public function __invoke(AskRequest $request, Answerer $answerer, Retriever $retriever): StreamedResponse|JsonResponse
    {
        // The agent is a different backend behind the same page: tools,
        // writes and approvals rather than passages. Its conversations live in
        // laravel/ai's store, so nothing below applies to it.
        if ($request->isAgentMode()) {
            return app(AssistantController::class)->ask($request);
        }

        // Checked before the thread is resolved: retrieval-only produces no
        // turn, and starting a conversation for it left an untitled empty row
        // in everybody's sidebar.
        if ($request->retrievalOnly()) {
            return response()->json($this->retrieveOnly($request, $retriever));
        }

        $conversation = $this->resolveConversation($request);

        $options = $this->options($request, $conversation);

        return config('rag.answering.stream', true)
            ? $this->streamed($request, $answerer, $conversation, $options)
            : response()->json($this->blocking($request, $answerer, $conversation, $options));
    }

    private function options(AskRequest $request, ?Conversation $conversation): AnswerOptions
    {
        return new AnswerOptions(
            retrieval: $request->retrievalOptions(),
            model: $request->model(),
            history: $conversation?->recentHistory((int) config('rag.chat.history_turns', 6)) ?? [],
            channel: QueryChannel::Web,
            userId: $request->user()?->getAuthIdentifier(),
            conversationId: $conversation?->getKey(),
            turn: $conversation === null ? null : $conversation->turns + 1,
        );
    }

    /**
     * Server-sent events.
     *
     * The session is written out before the first byte: once the response has
     * started streaming the framework can no longer persist it, and a request
     * that regenerates the session mid-stream leaves the next one holding a
     * stale CSRF token.
     */
    private function streamed(AskRequest $request, Answerer $answerer, ?Conversation $conversation, AnswerOptions $options): StreamedResponse
    {
        $question = $request->question();
        $allowed = ChatAbilities::allowed($request->user());
        $settings = $request->settings();

        $request->session()?->save();

        return response()->stream(function () use ($answerer, $question, $options, $conversation, $allowed, $settings): void {
            $this->send('start', [
                'conversation' => $conversation?->uuid,
                'turn' => $options->turn,
            ]);

            try {
                $stream = $answerer->stream($question, $options);

                foreach ($stream as $delta) {
                    $this->send('delta', ['text' => $delta]);
                }

                /** @var AnswerResult $result */
                $result = $stream->getReturn();

                $this->finishTurn($conversation, $result, $settings);

                $this->send('done', $this->done($result, $conversation, $allowed));
            } catch (Throwable $exception) {
                report($exception);

                $this->send('error', ['message' => $exception->getMessage()]);
            }
        }, 200, $this->eventStreamHeaders());
    }

    /**
     * @return array<string, mixed>
     */
    private function blocking(AskRequest $request, Answerer $answerer, ?Conversation $conversation, AnswerOptions $options): array
    {
        $result = $answerer->answer($request->question(), $options);

        $this->finishTurn($conversation, $result, $request->settings());

        return $this->done($result, $conversation, ChatAbilities::allowed($request->user()));
    }

    /**
     * Retrieval with no model call: no answer, no cost, just what matched.
     *
     * @return array<string, mixed>
     */
    private function retrieveOnly(AskRequest $request, Retriever $retriever): array
    {
        $allowed = ChatAbilities::allowed($request->user());

        abort_unless($allowed['advanced'] && $allowed['passages'], 403);

        $result = $retriever->retrieve($request->question(), $request->retrievalOptions());

        $passages = $result->chunks->values()->map(static fn (ScoredChunk $chunk, int $index): array => [
            'marker' => $index + 1,
            'label' => ($chunk->documentTitle ?? $chunk->externalId).' - '.$chunk->positionStart.'-'.$chunk->positionEnd,
            'score' => round($chunk->score, 4),
            'position_start' => $chunk->positionStart,
            'position_end' => $chunk->positionEnd,
            'content' => $chunk->content,
            'url' => $chunk->url,
            'used' => false,
        ])->all();

        return [
            'id' => null,
            'answer' => (string) trans_choice('rag::rag.chat.retrieval_only_answer', count($passages), ['count' => count($passages)]),
            'refused' => $passages === [],
            'retrieval_only' => true,
            'model' => null,
            'latency_ms' => $result->totalMs(),
            'cost_usd' => null,
            'passages' => $passages,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function finishTurn(?Conversation $conversation, AnswerResult $result, array $settings): void
    {
        if ($conversation === null) {
            return;
        }

        if ($settings !== []) {
            $conversation->settings = $settings;
            $conversation->save();
        }

        $conversation->registerTurn($result);
    }

    /**
     * The terminal payload, filtered to what this user may see.
     *
     * @param  array<string, bool>  $allowed
     * @return array<string, mixed>
     */
    private function done(AnswerResult $result, ?Conversation $conversation, array $allowed): array
    {
        return [
            'id' => $result->queryUuid,
            'conversation' => $conversation?->uuid,
            'answer' => $result->answer,
            'refused' => $result->refused,
            'model' => $allowed['model'] ? $result->model : null,
            'latency_ms' => $result->latencyMs,
            'cost_usd' => $allowed['cost'] ? round($result->usage->costUsd(), 6) : null,
            'tokens' => $allowed['cost'] ? [
                'prompt' => $result->usage->promptTokens,
                'completion' => $result->usage->completionTokens,
            ] : null,
            'conversation_cost_usd' => $allowed['cost'] ? $conversation?->costUsd() : null,
            'title' => $conversation?->title,
            'passages' => $allowed['passages'] ? $this->passages($result) : [],
        ];
    }

    /**
     * The same passage shape the Filament playground produces, so both
     * surfaces describe a retrieved passage identically.
     *
     * @return array<int, array<string, mixed>>
     */
    private function passages(AnswerResult $result): array
    {
        return $result->citations->map(static fn ($citation): array => [
            'marker' => $citation->marker,
            'label' => $citation->label,
            'score' => round($citation->chunk->score, 4),
            'position_start' => $citation->chunk->positionStart,
            'position_end' => $citation->chunk->positionEnd,
            'content' => $citation->chunk->content,
            'url' => $citation->chunk->url,
            'used' => $citation->used,
        ])->all();
    }

    private function resolveConversation(AskRequest $request): ?Conversation
    {
        if (! app(ChatPayload::class)->persists(ChatAbilities::allowed($request->user()))) {
            return null;
        }

        $uuid = $request->input('conversation');

        // The format is checked before the query, not after: on PostgreSQL the
        // column is a native uuid, so comparing it against "undefined" is not a
        // miss -- it is a cast error, and a 500. SQLite stores it as text and
        // would never show this.
        if (is_string($uuid) && Str::isUuid($uuid)) {
            $conversation = Conversation::query()->where('uuid', $uuid)->first();

            // A thread that no longer exists -- deleted in another tab, pruned,
            // or simply a corrupted id -- starts a new one. Failing the request
            // would strand somebody on a dead page with no way to ask anything.
            if ($conversation !== null) {
                $this->authorizeConversation($request, $conversation);

                return $conversation;
            }
        }

        return $this->newConversation($request);
    }
}
