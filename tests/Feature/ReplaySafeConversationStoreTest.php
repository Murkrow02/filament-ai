<?php

declare(strict_types=1);

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Murkrow\FilamentAi\Agent\Chat\ReplaySafeConversationStore;

/*
 * A turn that reads and then writes pauses with two calls on one row: the read
 * already answered, the write waiting. Replayed for Anthropic, every
 * tool_result must answer a tool_use in the message right before it, or the
 * provider refuses the continuation -- after the approved write has run.
 */

/**
 * The paused row as laravel/ai stores it, read back at the moment the approval
 * resumes the turn: the read has its result, the write does not yet. Shape
 * copied from a real Anthropic turn in the demo application.
 */
function pausedReadThenWrite(): object
{
    return (object) [
        'content' => 'Found the customer. Creating the task.',
        'tool_calls' => json_encode([
            ['id' => 'toolu_read', 'name' => 'customers_list', 'arguments' => ['per_page' => 1]],
            ['id' => 'toolu_write', 'name' => 'tasks_create', 'arguments' => ['title' => 'Prova']],
        ]),
        'approval_state' => json_encode(['pending' => ['toolu_write' => 'Create task -- Title: Prova']]),
        'meta' => json_encode([
            'provider' => 'anthropic',
            // Only the paused step's blocks: this is what the stock replay
            // gets wrong.
            'provider_content_blocks' => [
                ['type' => 'text', 'text' => 'Creating the task.'],
                ['type' => 'tool_use', 'id' => 'toolu_write', 'name' => 'tasks_create', 'input' => ['title' => 'Prova']],
            ],
        ]),
    ];
}

/**
 * @return array<int, \Laravel\Ai\Messages\Message>
 */
function replay(DatabaseConversationStore $store): array
{
    $record = pausedReadThenWrite();

    $method = new ReflectionMethod($store, 'reconstructToolTurn');

    return $method->invoke(
        $store,
        $record,
        collect(json_decode($record->tool_calls, true)),
        collect([
            ['id' => 'toolu_read', 'name' => 'customers_list', 'arguments' => ['per_page' => 1], 'result' => '[{"id":16}]', 'result_id' => 'toolu_read'],
        ]),
        ['toolu_read'],
    );
}

/**
 * Every tool result that answers a call the message before it did not make.
 *
 * @param  array<int, \Laravel\Ai\Messages\Message>  $messages
 * @return list<string>
 */
function orphanedResults(array $messages): array
{
    $orphans = [];

    foreach ($messages as $index => $message) {
        if (! $message instanceof ToolResultMessage) {
            continue;
        }

        $previous = $messages[$index - 1] ?? null;
        $asked = [];

        if ($previous instanceof AssistantMessage) {
            $asked = filled($previous->providerContentBlocks)
                ? collect($previous->providerContentBlocks)->where('type', 'tool_use')->pluck('id')->all()
                : $previous->toolCalls->pluck('id')->all();
        }

        foreach ($message->toolResults as $result) {
            if (! in_array($result->id, $asked, true)) {
                $orphans[] = $result->id;
            }
        }
    }

    return $orphans;
}

it('replays the earlier step before the paused one', function (): void {
    $messages = replay(new ReplaySafeConversationStore);

    expect(orphanedResults($messages))->toBe([])
        ->and($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls->pluck('id')->all())->toBe(['toolu_read'])
        ->and($messages[1]->toolResults->pluck('id')->all())->toBe(['toolu_read'])
        // The paused step keeps its raw blocks: Anthropic needs them to resume.
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->providerContentBlocks[1]['id'])->toBe('toolu_write');
});

it('is still needed: the stock store orphans the earlier result', function (): void {
    // When this starts failing, laravel/ai has fixed its replay: delete
    // ReplaySafeConversationStore and the binding in the service provider.
    expect(orphanedResults(replay(new DatabaseConversationStore)))->toBe(['toolu_read']);
});

it('replaces only the stock store', function (): void {
    expect(app(ConversationStore::class))->toBeInstanceOf(ReplaySafeConversationStore::class);
});
