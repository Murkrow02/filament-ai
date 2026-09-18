<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Agent\Chat\CitedPassages;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Agent\Solving\Solver;
use Murkrow\FilamentAi\Agent\Solving\Strategies;
use Murkrow\FilamentAi\Data\SolveOptions;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Filament\Resources\SolveRunResource;
use Murkrow\FilamentAi\Models\SolveRun;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Http\Concerns\StreamsServerSentEvents;
use Murkrow\FilamentAi\Http\Requests\AskRequest;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
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
            return response()->json(['message' => __('filament-ai::messages.assistant.not_installed')], 409);
        }

        $conversation = $this->turn->ownedConversation($request->conversationId(), $request->user());

        // A turn waiting for a decision must be decided first: laravel/ai
        // resumes from the latest stored turn, and a new question would bury
        // the pause where nobody can answer it.
        if ($this->turn->pending($conversation) !== []) {
            return response()->json([
                'message' => __('filament-ai::messages.assistant.decide_first'),
                'pending' => $this->approvalsFor($conversation),
            ], 409);
        }

        $assistant = $this->turn->assistant(
            $request->user(),
            $conversation,
            $request->contextResource(),
            $request->contextRecord(),
        )->withModel($request->model());

        return $this->stream($request, $assistant, $request->question(), $conversation);
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

        $request->session()?->save();

        return response()->stream(function () use ($question, $context, $user): void {
            $this->send('start', ['conversation' => null, 'solving' => true]);

            try {
                $run = app(Solver::class)->solve($question, new SolveOptions(
                    // The record on screen travels with every attempt, the
                    // same way it reaches a single turn's instructions.
                    context: array_filter(['page' => $context['label'], 'resource' => $context['resource'], 'record' => $context['record']]),
                ), $user?->getAuthIdentifier());

                $this->follow($run);
            } catch (Throwable $exception) {
                report($exception);

                $this->send('error', ['message' => $this->failureMessage($exception)]);
            }
        }, 200, $this->eventStreamHeaders());
    }

    /**
     * Report a run's progress until it ends, or until waiting stops making
     * sense.
     */
    private function follow(SolveRun $run): void
    {
        $budget = (int) (($run->budgets['max_seconds'] ?? null) ?: config('filament-ai.agent.solving.max_seconds', 300));
        $deadline = time() + $budget + self::SOLVE_GRACE_SECONDS;
        $last = null;

        while (true) {
            $run->refresh();

            $snapshot = $this->solveSnapshot($run);

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
            'cost_usd' => ChatAbilities::allows('cost') ? round($run->costUsd(), 6) : null,
            'model' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function solveSnapshot(SolveRun $run): array
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
            // turned down -- for whoever may read it.
            'url' => $this->solveRunUrl($run),
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
        abort_unless(ChatAbilities::canUseChat($request->user()) && $this->turn->available(), 403);

        $owned = $this->turn->ownedConversation($conversation, $request->user());

        abort_if($owned === null, 404);

        $data = $request->validate([
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*' => ['required', 'boolean'],
        ]);

        $decisions = $this->turn->decisions($owned, $data['decisions']);

        if (! $decisions instanceof Decisions) {
            // Either nothing is waiting any more -- another tab decided it --
            // or the browser answered only part of the pause.
            return response()->json([
                'message' => __('filament-ai::messages.assistant.nothing_pending'),
                'pending' => $this->approvalsFor($owned),
            ], 409);
        }

        $assistant = $this->turn->assistant(
            $request->user(),
            $owned,
            is_string($request->input('resource')) ? $request->input('resource') : null,
            is_string($request->input('record')) ? $request->input('record') : null,
        );

        return $this->stream($request, $assistant, $decisions, $owned);
    }

    /**
     * The turn itself, event by event.
     */
    private function stream(Request $request, PanelAssistant $assistant, Decisions|string $prompt, ?string $conversation): StreamedResponse
    {
        $allowed = ChatAbilities::allowed($request->user());

        // Written out before the first byte: once the response is streaming
        // the framework can no longer persist the session, and a request that
        // regenerates it mid-stream leaves the next one with a stale token.
        $request->session()?->save();

        // What the assistant reads while it answers, so the citations in the
        // answer point at something the reader can open.
        $cited = app(CitedPassages::class);
        $cited->collect();

        return response()->stream(function () use ($assistant, $prompt, $conversation, $allowed, $cited): void {
            $this->send('start', ['conversation' => $conversation]);

            try {
                $stream = $assistant->stream($prompt);

                $final = null;
                $stream->then(function (StreamedAgentResponse $response) use (&$final): void {
                    $final = $response;
                });

                $sent = 0;

                foreach ($stream as $event) {
                    $this->relay($event);

                    // Passages appear as the tools return them, so the list
                    // fills in while the answer is still being written.
                    if ($cited->count() > $sent) {
                        $this->send('sources', ['passages' => array_slice($cited->all(), $sent)]);
                        $sent = $cited->count();
                    }
                }

                $conversation = $final?->conversationId ?? $conversation;

                $this->send('done', $this->done($final, $conversation, $allowed) + ['passages' => $cited->all()]);
            } catch (Throwable $exception) {
                report($exception);

                $this->send('error', ['message' => $this->failureMessage($exception)]);
            } finally {
                $cited->stop();
            }
        }, 200, $this->eventStreamHeaders());
    }

    /**
     * One streamed event, translated into the page's vocabulary. Anything else
     * -- reasoning, citations, stream bookkeeping -- is not the page's business.
     */
    private function relay(object $event): void
    {
        match (true) {
            $event instanceof TextDelta => $this->send('delta', ['text' => $event->delta]),
            $event instanceof ToolCallEvent => $this->send('tool', [
                'id' => $event->toolCall->id,
                'name' => $event->toolCall->name,
                'status' => 'running',
            ]),
            $event instanceof ToolResultEvent => $this->send('tool', [
                'id' => $event->toolResult->id,
                'name' => $event->toolResult->name,
                'status' => match (true) {
                    $event->denied => 'denied',
                    ! $event->successful => 'failed',
                    default => 'done',
                },
                'error' => $event->error,
            ]),
            $event instanceof ToolApprovalRequest => $this->send('approval', [
                'calls' => $event->pendingApprovals
                    ->map(static fn (PendingApproval $approval): array => AssistantTurn::card(
                        $approval->id, $approval->tool, $approval->reason, $approval->arguments,
                    ))
                    ->values()
                    ->all(),
            ]),
            $event instanceof ErrorEvent => $this->send('error', ['message' => $event->message]),
            default => null,
        };
    }

    /**
     * @param  array<string, bool>  $allowed
     * @return array<string, mixed>
     */
    private function done(?StreamedAgentResponse $response, ?string $conversation, array $allowed): array
    {
        $promptTokens = (int) ($response?->usage->promptTokens ?? 0);
        $completionTokens = (int) ($response?->usage->completionTokens ?? 0);

        return [
            'conversation' => $conversation,
            'answer' => (string) ($response?->text ?? ''),
            // What is still waiting after the turn: laravel/ai's store is the
            // only authority on that, not the events we happened to see.
            'pending' => $this->approvalsFor($conversation),
            'tokens' => $allowed['cost'] ? ['prompt' => $promptTokens, 'completion' => $completionTokens] : null,
            'cost_usd' => $allowed['cost']
                ? round(CostCalculator::completionMicros((string) ($response?->meta->model ?? ''), $promptTokens, $completionTokens) / 1_000_000, 6)
                : null,
            'model' => $allowed['model'] ? ($response?->meta->model ?? null) : null,
        ];
    }

    /**
     * @return list<array{id: string, tool: string, reason: ?string, arguments: array<string, string>}>
     */
    private function approvalsFor(?string $conversation): array
    {
        return $this->turn->approvalCards($conversation);
    }

    /**
     * Provider internals and SQL can travel in an exception message, so the
     * detail is shown only where the application already shows them.
     */
    private function failureMessage(Throwable $exception): string
    {
        return (string) __('filament-ai::messages.assistant.failed')
            .(config('app.debug') ? ' ('.$exception->getMessage().')' : '');
    }
}
