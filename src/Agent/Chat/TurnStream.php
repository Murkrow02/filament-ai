<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Generator;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Ingestion\CostCalculator;

/**
 * One streamed turn of the assistant, translated into the page's events.
 *
 * This is the only place the chat reads laravel/ai's stream: its event
 * classes, their fields and the final response. The controller relays what
 * comes out of here and never imports laravel/ai itself, so a change in the
 * SDK's stream shape is one class to fix.
 *
 * What an event carries depends on who reads it. Everyone gets a label in
 * their language and a status; the `debug` ability adds the tool's name, the
 * raw error and the arguments.
 */
final class TurnStream
{
    public function __construct(
        private readonly ToolLabels $labels,
        private readonly ApprovalCards $cards,
    ) {}

    /**
     * @return Generator<int, array{0: string, 1: array<string, mixed>}, mixed, TurnSummary>
     */
    public function run(PanelAssistant $assistant, Decisions|string $prompt, bool $debug): Generator
    {
        $stream = $assistant->stream($prompt);

        $final = null;
        $stream->then(function (StreamedAgentResponse $response) use (&$final): void {
            $final = $response;
        });

        foreach ($stream as $event) {
            $translated = $this->translate($event, $debug);

            if ($translated !== null) {
                yield $translated;
            }
        }

        /** @var StreamedAgentResponse|null $final */
        $input = (int) ($final?->usage->inputTokens ?? 0);
        $output = (int) ($final?->usage->outputTokens ?? 0);
        $model = $final?->meta->model ?? null;

        return new TurnSummary(
            text: (string) ($final?->text ?? ''),
            conversationId: $final?->conversationId,
            model: $model === null ? null : (string) $model,
            inputTokens: $input,
            outputTokens: $output,
            costUsd: round(CostCalculator::completionMicros((string) $model, $input, $output) / 1_000_000, 6),
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function translate(object $event, bool $debug): ?array
    {
        return match (true) {
            $event instanceof TextDelta => ['delta', ['text' => $event->delta]],

            $event instanceof ToolCallEvent => ['tool', $this->tool($event->toolCall->id, $event->toolCall->name, 'running', null, $debug)],

            // A sub-agent reports progress with preliminary results; the call
            // is not finished until the last one.
            $event instanceof ToolResultEvent && $event->preliminary => null,

            $event instanceof ToolResultEvent => ['tool', $this->tool(
                $event->toolResult->id,
                $event->toolResult->name,
                match (true) {
                    $event->denied => 'denied',
                    // A tool that answered "Error: ..." ran, but did not do
                    // what was asked: the step says so.
                    ! $event->successful, str_starts_with((string) $event->toolResult->result, 'Error:') => 'failed',
                    default => 'done',
                },
                $event->error ?? (str_starts_with((string) $event->toolResult->result, 'Error:') ? (string) $event->toolResult->result : null),
                $debug,
            )],

            $event instanceof ToolApprovalRequest => ['approval', [
                'calls' => $event->pendingApprovals
                    ->map(fn (PendingApproval $approval): array => $this->cards->card(
                        $approval->id, $approval->tool, $approval->reason, $approval->arguments, $debug,
                    ))
                    ->values()
                    ->all(),
            ]],

            $event instanceof ErrorEvent => ['error', array_filter([
                'message' => (string) __('filament-ai::messages.assistant.failed'),
                'detail' => $debug ? $event->message : null,
            ])],

            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(string $id, string $name, string $status, ?string $error, bool $debug): array
    {
        return array_filter([
            'id' => $id,
            'label' => $this->labels->label($name),
            'status' => $status,
            'name' => $debug ? $name : null,
            'error' => $debug ? $error : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
