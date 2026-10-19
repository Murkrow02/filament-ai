<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Agent\Chat\CitedPassages;
use Murkrow\FilamentAi\Agent\Chat\TurnStream;
use Murkrow\FilamentAi\Agent\Chat\TurnSummary;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Http\Concerns\StreamsServerSentEvents;
use Murkrow\FilamentAi\Http\Requests\AskRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Asking the assistant and deciding what it waits on: one turn, answered.
 *
 * Shared by the browser chat and the token API so the two cannot drift apart:
 * the same lock, the same pending check, the same events. Only what differs
 * between them is a parameter -- which assistant answers, whether it may reach
 * the panel's resources, and whether the turn is streamed or answered as one
 * JSON document for a client that cannot read a stream.
 */
final class ChatTurnResponder
{
    use StreamsServerSentEvents;

    public function __construct(
        private readonly AssistantTurn $turn,
    ) {}

    /**
     * @param  class-string<PanelAssistant>|null  $assistant  null: `filament-ai.agent.assistant`
     */
    public function ask(AskRequest $request, ?string $assistant = null, bool $resources = true, bool $stream = true): StreamedResponse|JsonResponse
    {
        if (! $this->turn->available()) {
            return $this->unavailable($request);
        }

        $conversation = $this->turn->ownedConversation($request->conversationId(), $request->user());
        $lock = $conversation === null ? null : $this->lockTurn($conversation);

        if ($lock === false) {
            return $this->busy();
        }

        // A turn waiting for a decision must be decided first: laravel/ai
        // resumes from the latest stored turn, and a new question would bury
        // the pause where nobody can answer it.
        if ($this->turn->pending($conversation) !== []) {
            $lock?->release();

            return response()->json([
                'message' => __('filament-ai::messages.assistant.decide_first'),
                'pending' => $this->approvalsFor($conversation, $request),
            ], 409);
        }

        $agent = $this->turn->assistant(
            $request->user(),
            $conversation,
            $resources ? $request->contextResource() : null,
            $resources ? $request->contextRecord() : null,
            $assistant,
            $resources,
        )->withModel($request->model());

        return $this->respond($request, $agent, $request->question(), $conversation, $lock ?: null, $stream);
    }

    /**
     * Approve or reject the calls a paused turn is waiting on, and answer with
     * what the agent does next.
     *
     * @param  class-string<PanelAssistant>|null  $assistant
     */
    public function decide(Request $request, string $conversation, ?string $assistant = null, bool $resources = true, bool $stream = true): StreamedResponse|JsonResponse
    {
        abort_unless(ChatAbilities::canUseChat($request->user()), 403);

        if (! $this->turn->available()) {
            return $this->unavailable($request);
        }

        $owned = $this->turn->ownedConversation($conversation, $request->user());

        abort_if($owned === null, 404);

        $data = $request->validate([
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*' => ['required', 'boolean'],
        ]);

        // One decision at a time per conversation. laravel/ai records what an
        // approved call returned only after it has run, so two requests that
        // both read "pending" -- a double click, a second tab, a retry --
        // would otherwise both run the write.
        $lock = $this->lockTurn($owned);

        if ($lock === false) {
            return $this->busy();
        }

        // Read under the lock: whatever a request that just finished decided
        // is no longer pending.
        $decisions = $this->turn->decisions($owned, $data['decisions']);

        if ($decisions === null) {
            $lock->release();

            // Either nothing is waiting any more -- another tab decided it --
            // or the client answered only part of the pause.
            return response()->json([
                'message' => __('filament-ai::messages.assistant.nothing_pending'),
                'pending' => $this->approvalsFor($owned, $request),
            ], 409);
        }

        $agent = $this->turn->assistant(
            $request->user(),
            $owned,
            $resources && is_string($request->input('resource')) ? $request->input('resource') : null,
            $resources && is_string($request->input('record')) ? $request->input('record') : null,
            $assistant,
            $resources,
        );

        return $this->respond($request, $agent, $decisions, $owned, $lock, $stream);
    }

    /**
     * The conversation's turn lock, or false when another request holds it.
     */
    private function lockTurn(string $conversation): Lock|false
    {
        $lock = Cache::lock('filament-ai:turn:'.$conversation, (int) config('filament-ai.agent.chat.turn_lock_seconds', 600));

        return $lock->get() ? $lock : false;
    }

    private function busy(): JsonResponse
    {
        return response()->json(['message' => __('filament-ai::messages.assistant.busy')], 409);
    }

    /**
     * The turn itself, event by event, either streamed or collected into the
     * final `done` (or `error`) document. What the events carry is decided by
     * `TurnStream`, for this user's abilities.
     */
    private function respond(Request $request, PanelAssistant $assistant, object|string $prompt, ?string $conversation, ?Lock $lock, bool $stream): StreamedResponse|JsonResponse
    {
        $allowed = ChatAbilities::allowed($request->user());

        // Written out before the first byte: once the response is streaming
        // the framework can no longer persist the session, and a request that
        // regenerates it mid-stream leaves the next one with a stale token.
        // A token-authenticated request has no session at all, and asking
        // for one would throw.
        if ($request->hasSession()) {
            $request->session()->save();
        }

        $run = function (callable $emit) use ($assistant, $prompt, $conversation, $allowed, $lock): void {
            $this->run($emit, $assistant, $prompt, $conversation, $allowed, $lock);
        };

        if ($stream) {
            return response()->stream(function () use ($run): void {
                $run(fn (string $event, array $data) => $this->send($event, $data));
            }, 200, $this->eventStreamHeaders());
        }

        $last = null;

        $run(static function (string $event, array $data) use (&$last): void {
            if ($event === 'done' || $event === 'error') {
                $last = [$event, $data];
            }
        });

        [$event, $data] = $last ?? ['error', $this->failure(null, false)];

        return response()->json($data, $event === 'done' ? 200 : 502);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $emit
     * @param  array<string, bool>  $allowed
     */
    private function run(callable $emit, PanelAssistant $assistant, object|string $prompt, ?string $conversation, array $allowed, ?Lock $lock): void
    {
        $turn = app(TurnStream::class);

        // What the assistant reads while it answers, so the citations in the
        // answer point at something the reader can open.
        $cited = app(CitedPassages::class);
        $cited->collect();

        $emit('start', ['conversation' => $conversation]);

        try {
            $events = $turn->run($assistant, $prompt, $allowed['debug']);
            $sent = 0;

            foreach ($events as [$event, $data]) {
                $emit($event, $data);

                // Passages appear as the tools return them, so the list
                // fills in while the answer is still being written.
                if ($cited->count() > $sent) {
                    $emit('sources', ['passages' => $this->passages(array_slice($cited->all(), $sent), $allowed['debug'])]);
                    $sent = $cited->count();
                }
            }

            /** @var TurnSummary $summary */
            $summary = $events->getReturn();
            $conversation = $summary->conversationId ?? $conversation;

            $emit('done', $this->done($summary, $conversation, $allowed) + [
                'passages' => $this->passages($cited->all(), $allowed['debug']),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $emit('error', $this->failure($exception, $allowed['debug']));
        } finally {
            $cited->stop();
            $lock?->release();
        }
    }

    /**
     * @param  array<string, bool>  $allowed
     * @return array<string, mixed>
     */
    private function done(TurnSummary $summary, ?string $conversation, array $allowed): array
    {
        return [
            'conversation' => $conversation,
            'answer' => $summary->text,
            // What is still waiting after the turn: laravel/ai's store is the
            // only authority on that, not the events we happened to see.
            'pending' => $this->turn->approvalCards($conversation, $allowed['debug']),
            'cost_usd' => $allowed['cost'] || $allowed['debug'] ? $summary->costUsd : null,
            'model' => $allowed['debug'] ? $summary->model : null,
            'tokens' => $allowed['debug'] ? ['input' => $summary->inputTokens, 'output' => $summary->outputTokens] : null,
        ];
    }

    /**
     * A passage's score and document id say how retrieval went, which is the
     * debugging view's business; the reader gets the text and where it is from.
     *
     * @param  list<array<string, mixed>>  $passages
     * @return list<array<string, mixed>>
     */
    private function passages(array $passages, bool $debug): array
    {
        return $debug ? $passages : array_map(
            static fn (array $passage): array => array_diff_key($passage, ['score' => true, 'document_id' => true]),
            $passages,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvalsFor(?string $conversation, Request $request): array
    {
        return $this->turn->approvalCards($conversation, ChatAbilities::allows('debug', $request->user()));
    }

    /**
     * laravel/ai's tables are missing, or predate its 1.0 schema. The person
     * asking can do nothing about that; whoever can is told how.
     */
    private function unavailable(Request $request): JsonResponse
    {
        return response()->json(array_filter([
            'message' => __('filament-ai::messages.assistant.unavailable'),
            'detail' => ChatAbilities::allows('debug', $request->user()) ? __('filament-ai::messages.assistant.not_installed') : null,
        ]), 409);
    }

    /**
     * Provider internals and SQL travel in an exception message: the reader
     * gets a sentence, and only the `debug` ability gets the message itself.
     *
     * @return array{message: string, detail?: string}
     */
    private function failure(?Throwable $exception, bool $debug): array
    {
        return array_filter([
            'message' => (string) __('filament-ai::messages.assistant.failed'),
            'detail' => $debug ? $exception?->getMessage() : null,
        ]);
    }
}
