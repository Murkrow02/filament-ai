<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * laravel/ai's conversation store, with one replay bug worked around.
 *
 * A turn that reads first and then writes -- "find the first customer and
 * create a task for them" -- is two steps: the read runs at once, the write
 * pauses for approval. laravel/ai 0.11.2 stores both calls on the paused row,
 * but when it replays that row for Anthropic it rebuilds the assistant
 * message from the raw content blocks of the *paused step only*, while still
 * sending the results of *both* calls. Anthropic answers 400 ("unexpected
 * `tool_use_id` found in `tool_result` blocks"), after the approved write has
 * already run -- so the user sees an error for a change that was made.
 *
 * The fix is to replay the earlier step as what it was: its own assistant
 * message with its calls, followed by its results, and only then the paused
 * step. Everything else is left to the parent. Remove this class once
 * laravel/ai replays multi-step pauses correctly.
 */
class ReplaySafeConversationStore extends DatabaseConversationStore
{
    /**
     * @param  Collection<int, array<string, mixed>>  $toolCalls
     * @param  Collection<int, array<string, mixed>>  $toolResults
     * @param  array<int, string>  $resolvedCallIds
     * @return array<int, \Laravel\Ai\Messages\Message>
     */
    protected function reconstructToolTurn(object $record, Collection $toolCalls, Collection $toolResults, array $resolvedCallIds = []): array
    {
        $meta = (array) json_decode($record->meta ?? '[]', true);

        $pausedStepIds = collect($meta['provider_content_blocks'] ?? [])
            ->filter(static fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_use')
            ->pluck('id')
            ->filter()
            ->all();

        // No raw blocks means the parent's generic replay applies, and it is
        // correct there.
        if ($pausedStepIds === []) {
            return parent::reconstructToolTurn($record, $toolCalls, $toolResults, $resolvedCallIds);
        }

        $callIds = $toolCalls->pluck('id')->all();
        $resultsById = $toolResults->keyBy('id');

        $earlierStep = $toolCalls
            ->filter(static fn (array $call): bool => ! in_array($call['id'] ?? null, $pausedStepIds, true)
                && $resultsById->has($call['id'] ?? ''))
            ->values();

        if ($earlierStep->isEmpty()) {
            return parent::reconstructToolTurn($record, $toolCalls, $toolResults, $resolvedCallIds);
        }

        $earlierIds = $earlierStep->pluck('id')->all();

        // Results that belong to a previous row come first, exactly where the
        // parent would have put them.
        $prior = $toolResults->reject(static fn (array $result): bool => in_array($result['id'], $callIds, true))->values();

        $messages = [];

        if ($prior->isNotEmpty()) {
            $messages[] = new ToolResultMessage($prior->map(ToolResult::fromArray(...))->values());
        }

        $messages[] = new AssistantMessage('', $earlierStep->map(ToolCall::fromArray(...))->values());
        $messages[] = new ToolResultMessage(
            $earlierStep->map(static fn (array $call): ToolResult => ToolResult::fromArray($resultsById[$call['id']]))->values()
        );

        $pausedCalls = $toolCalls->reject(static fn (array $call): bool => in_array($call['id'] ?? null, $earlierIds, true))->values();
        $pausedResults = $toolResults
            ->reject(static fn (array $result): bool => in_array($result['id'], $earlierIds, true) || ! in_array($result['id'], $callIds, true))
            ->values();

        return [...$messages, ...parent::reconstructToolTurn($record, $pausedCalls, $pausedResults, $resolvedCallIds)];
    }
}
