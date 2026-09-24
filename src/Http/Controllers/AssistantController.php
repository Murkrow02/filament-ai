<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Agent\Chat\CitedPassages;
use Murkrow\FilamentAi\Agent\Chat\TurnStream;
use Murkrow\FilamentAi\Agent\Chat\TurnSummary;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Agent\Solving\Solver;
use Murkrow\FilamentAi\Agent\Solving\Strategies;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Data\SolveOptions;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Filament\Resources\SolveRunResource;
use Murkrow\FilamentAi\Http\Concerns\StreamsServerSentEvents;
use Murkrow\FilamentAi\Http\Requests\AskRequest;
use Murkrow\FilamentAi\Models\SolveRun;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Asking the assistant: one turn, streamed.
 *
 * Text as it is written, a `tool` event whenever it reaches for something, an
 * `approval` event when a change needs the user's word before it runs, and
 * `solve` events when the question was asked with "keep trying" on.
 */
class AssistantController
{
    use StreamsServerSentEvents;

    /** How often a followed run is read back. */
    private const SOLVE_POLL_MICROSECONDS = 750_000;

    /** Slack past the run's own time budget before the page stops waiting. */
    private const SOLVE_GRACE_SECONDS = 60;

    public function __construct(
        private readonly AssistantTurn $turn,
    ) {}

    /**
     * Ask the assistant.
     */
    public function ask(AskRequest $request): StreamedResponse|JsonResponse
    {
        if ($request->solves()) {
            return $this->solve($request);
        }

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

        $assistant = $this->turn->assistant(
            $request->user(),
            $conversation,
            $request->contextResource(),
            $request->contextRecord(),
        )->withModel($request->model());

        return $this->stream($request, $assistant, $request->question(), $conversation, $lock ?: null);
    }

    /**
     * Keep trying instead of answering once: start an iterative run and follow
     * it until it ends.
     *
     * The run itself happens on the queue, in waves of parallel attempts; this
     * request only reads it back and reports each change as a `solve` event.
     * If the page goes away the run carries on and stays readable in the
     * panel -- it has already spent the money, and the answer is still worth
     * having.
     */
    public function solve(AskRequest $request): StreamedResponse|JsonResponse
    {
        if (! $this->turn->available() || ! $request->solves()) {
            return response()->json(['message' => __('filament-ai::messages.chat.forbidden')], 403);
        }

        $context = $this->turn->resolvedContext($request->contextResource(), $request->contextRecord());
        $user = $request->user();
        $question = $request->question();
        $allowed = ChatAbilities::allowed($user);

        $request->session()?->save();

        return response()->stream(function () use ($question, $context, $user, $allowed): void {
            $this->send('start', ['conversation' => null, 'solving' => true]);

            try {
                $run = app(Solver::class)->solve($question, new SolveOptions(
                    // The record on screen travels with every attempt, the
                    // same way it reaches a single turn's instructions.
                    context: array_filter(['page' => $context['label'], 'resource' => $context['resource'], 'record' => $context['record']]),
                ), $user?->getAuthIdentifier());

                $this->follow($run, $allowed);
            } catch (Throwable $exception) {
                report($exception);

                $this->send('error', $this->failure($exception, $allowed['debug']));
            }
        }, 200, $this->eventStreamHeaders());
    }

    /**
     * Report a run's progress until it ends, or until waiting stops making
     * sense.
     */
    /**
     * @param  array<string, bool>  $allowed
     */
    private function follow(SolveRun $run, array $allowed): void
    {
        $budget = (int) (($run->budgets['max_seconds'] ?? null) ?: config('filament-ai.agent.solving.max_seconds', 300));
        $deadline = time() + $budget + self::SOLVE_GRACE_SECONDS;
        $last = null;

        while (true) {
            $run->refresh();

            $snapshot = $this->solveSnapshot($run, $allowed['debug']);

            // Only what changed goes out: a poll every 750ms that repeated
            // itself would be a stream of noise.
            if ($snapshot !== $last) {
                $this->send('solve', $snapshot);
                $last = $snapshot;
            }

            if ($run->status->isTerminal()) {
                break;
            }

            if (time() > $deadline || connection_aborted()) {
                $this->send('error', ['message' => __('filament-ai::messages.solving.still_running', ['run' => substr($run->uuid, 0, 8)])]);

                return;
            }

            usleep(self::SOLVE_POLL_MICROSECONDS);
        }

        $run->loadMissing('best');

        $this->send('done', [
            'conversation' => null,
            'answer' => $run->status === SolveStatus::Solved
                ? (string) ($run->best?->finalAnswer() ?? $run->message)
                : (string) $run->message,
            'pending' => [],
            'solve' => $snapshot,
            'tokens' => null,
            'cost_usd' => $allowed['cost'] || $allowed['debug'] ? round($run->costUsd(), 6) : null,
            'model' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function solveSnapshot(SolveRun $run, bool $debug): array
    {
        return [
            'run' => $run->uuid,
            'status' => $run->status->value,
            'wave' => max(1, (int) $run->wave),
            'waves' => (int) $run->waves_total,
            'attempts' => (int) $run->attempts_total,
            'attempts_total' => Strategies::plannedAttempts(
                Strategies::for($run->strategy),
                (int) $run->waves_total,
                (int) $run->attempts_per_wave,
            ),
            'best_score' => (int) $run->best_score,
            // A link to the whole story -- every attempt, and why each one was
            // turned down -- for whoever may read it: it is a debugging view.
            'url' => $debug ? $this->solveRunUrl($run) : null,
        ];
    }

    private function solveRunUrl(SolveRun $run): ?string
    {
        try {
            return SolveRunResource::canAccess() ? SolveRunResource::getUrl('view', ['record' => $run]) : null;
        } catch (Throwable) {
            // No panel in this request, or the resource is not on it.
            return null;
        }
    }

    /**
     * Approve or reject the calls a paused turn is waiting on, and stream what
     * the agent does next.
     */
    public function decide(Request $request, string $conversation): StreamedResponse|JsonResponse
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
            // or the browser answered only part of the pause.
            return response()->json([
                'message' => __('filament-ai::messages.assistant.nothing_pending'),
                'pending' => $this->approvalsFor($owned, $request),
            ], 409);
        }

        $assistant = $this->turn->assistant(
            $request->user(),
            $owned,
            is_string($request->input('resource')) ? $request->input('resource') : null,
            is_string($request->input('record')) ? $request->input('record') : null,
        );

        return $this->stream($request, $assistant, $decisions, $owned, $lock);
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
     * The turn itself, event by event. What the events carry is decided by
     * `TurnStream`, for this user's abilities.
     */
    private function stream(Request $request, PanelAssistant $assistant, object|string $prompt, ?string $conversation, ?Lock $lock = null): StreamedResponse
    {
        $allowed = ChatAbilities::allowed($request->user());
        $turn = app(TurnStream::class);

        // Written out before the first byte: once the response is streaming
        // the framework can no longer persist the session, and a request that
        // regenerates it mid-stream leaves the next one with a stale token.
        $request->session()?->save();

        // What the assistant reads while it answers, so the citations in the
        // answer point at something the reader can open.
        $cited = app(CitedPassages::class);
        $cited->collect();

        return response()->stream(function () use ($turn, $assistant, $prompt, $conversation, $allowed, $cited, $lock): void {
            $this->send('start', ['conversation' => $conversation]);

            try {
                $events = $turn->run($assistant, $prompt, $allowed['debug']);
                $sent = 0;

                foreach ($events as [$event, $data]) {
                    $this->send($event, $data);

                    // Passages appear as the tools return them, so the list
                    // fills in while the answer is still being written.
                    if ($cited->count() > $sent) {
                        $this->send('sources', ['passages' => $this->passages(array_slice($cited->all(), $sent), $allowed['debug'])]);
                        $sent = $cited->count();
                    }
                }

                /** @var TurnSummary $summary */
                $summary = $events->getReturn();
                $conversation = $summary->conversationId ?? $conversation;

                $this->send('done', $this->done($summary, $conversation, $allowed) + [
                    'passages' => $this->passages($cited->all(), $allowed['debug']),
                ]);
            } catch (Throwable $exception) {
                report($exception);

                $this->send('error', $this->failure($exception, $allowed['debug']));
            } finally {
                $cited->stop();
                $lock?->release();
            }
        }, 200, $this->eventStreamHeaders());
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
    private function failure(Throwable $exception, bool $debug): array
    {
        return array_filter([
            'message' => (string) __('filament-ai::messages.assistant.failed'),
            'detail' => $debug ? $exception->getMessage() : null,
        ]);
    }
}
