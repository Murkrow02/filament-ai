<?php

declare(strict_types=1);

use Laravel\Ai\Responses\Data\ToolCall;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * The same turn, seen by a person and by whoever debugs the assistant.
 */

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
});

function scriptedTurn(): array
{
    TestBook::query()->create(['title' => 'Statuti']);

    scriptAgent([
        new ToolCall('call_0', 'test_books_list', []),
        new ToolCall('call_1', 'test_books_edit', ['id' => (string) TestBook::query()->value('id'), 'title' => 'Statuti del comune']),
        'Done.',
    ]);

    return eventsOf(test()->post('/ai/chat/ask', ['question' => 'Rename it']));
}

it('shows a person what happened in words, and nothing technical', function (): void {
    $events = scriptedTurn();

    $tools = array_values(array_filter($events, fn (array $event): bool => $event['event'] === 'tool'));
    $done = lastEvent($events, 'done');

    expect($tools[0]['data'])->toBe(['id' => 'call_0', 'label' => 'Search test books', 'status' => 'running'])
        ->and($done['pending'][0])->toMatchArray([
            'title' => 'Edit test book «Statuti»',
            'record' => 'Statuti',
            'changes' => [['label' => 'Title', 'before' => 'Statuti', 'after' => 'Statuti del comune']],
        ])
        ->and($done['pending'][0])->not->toHaveKeys(['tool', 'arguments'])
        ->and($done['model'])->toBeNull()
        ->and($done['tokens'])->toBeNull()
        ->and($done['cost_usd'])->toBeNull();
});

it('adds the technical detail for the debug ability', function (): void {
    config()->set('filament-ai.chat.abilities.debug', true);

    $events = scriptedTurn();

    $tools = array_values(array_filter($events, fn (array $event): bool => $event['event'] === 'tool'));
    $done = lastEvent($events, 'done');

    expect($tools[0]['data']['name'])->toBe('test_books_list')
        ->and($done['pending'][0]['tool'])->toBe('test_books_edit')
        ->and($done['pending'][0]['arguments'])->toHaveKey('title', 'Statuti del comune')
        ->and($done['tokens'])->toHaveKeys(['input', 'output'])
        ->and($done['cost_usd'])->not->toBeNull();
});

it('reports a failure as a sentence, with the error only for debug', function (): void {
    // Anything the fake gateway cannot turn into a response makes the turn
    // fail with an exception whose message is internal.
    scriptAgent([new stdClass]);

    $error = lastEvent(eventsOf($this->post('/ai/chat/ask', ['question' => 'Anything'])), 'error');

    expect($error)->toBe(['message' => __('filament-ai::messages.assistant.failed')]);

    config()->set('filament-ai.chat.abilities.debug', true);
    scriptAgent([new stdClass]);

    $error = lastEvent(eventsOf($this->post('/ai/chat/ask', ['question' => 'Anything'])), 'error');

    expect($error['message'])->toBe(__('filament-ai::messages.assistant.failed'))
        ->and($error['detail'])->toBeString()->not->toBe('');
});
