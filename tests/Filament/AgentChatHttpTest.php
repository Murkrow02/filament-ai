<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * The agent mode of the chat, over the wire the page actually uses.
 *
 * Responses are scripted on the text provider rather than through
 * PanelAssistant::fake(), which skips approval resumption -- the one thing
 * these tests most need to be real.
 */

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
});

/**
 * @param  list<mixed>  $responses
 */
function scriptAgent(array $responses): void
{
    Ai::textProvider()->useTextGateway(new FakeTextGateway($responses));
}

/**
 * Every server-sent event of a response, in order.
 *
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function eventsOf(TestResponse $response): array
{
    preg_match_all('/event: (\S+)\ndata: (.*)\n/', $response->streamedContent(), $matches, PREG_SET_ORDER);

    return array_map(static fn (array $match): array => [
        'event' => $match[1],
        'data' => json_decode($match[2], true),
    ], $matches);
}

/**
 * @param  list<array{event: string, data: array<string, mixed>}>  $events
 * @return array<string, mixed>
 */
function lastEvent(array $events, string $name): array
{
    $found = array_values(array_filter($events, static fn (array $event): bool => $event['event'] === $name));

    expect($found)->not->toBeEmpty("no {$name} event was sent");

    return end($found)['data'];
}

it('streams an agent answer and keeps the conversation', function (): void {
    scriptAgent(['There are no books yet.']);

    $response = $this->post('/rag/chat/ask', ['question' => 'How many books are there?', 'mode' => 'agent']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $events = eventsOf($response);
    $done = lastEvent($events, 'done');

    expect(array_column($events, 'event'))->toContain('start', 'delta', 'done')
        ->and($done['mode'])->toBe('agent')
        ->and($done['answer'])->toBe('There are no books yet.')
        ->and($done['pending'])->toBe([])
        ->and($done['conversation'])->toBe(Conversation::query()->sole()->id);
});

it('asks for approval before a write and applies it once approved', function (): void {
    scriptAgent([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune']),
        'I added the book.',
    ]);

    $events = eventsOf($this->post('/rag/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
        'mode' => 'agent',
    ]));

    $approval = lastEvent($events, 'approval');
    $done = lastEvent($events, 'done');

    expect($approval['calls'][0]['id'])->toBe('call_1')
        ->and($approval['calls'][0]['tool'])->toBe('test_books_create')
        // The arguments are what the user is approving; without them the
        // button asks for a blank cheque.
        ->and($approval['calls'][0]['arguments'])->toBe(['title' => 'Statuti del comune'])
        ->and($done['pending'][0]['id'])->toBe('call_1')
        // A reload draws the same card as the stream did.
        ->and($done['pending'][0]['arguments'])->toBe(['title' => 'Statuti del comune'])
        ->and(TestBook::query()->count())->toBe(0);

    $resumed = eventsOf($this->post("/rag/chat/a/{$done['conversation']}/decisions", [
        'decisions' => ['call_1' => true],
    ]));

    $after = lastEvent($resumed, 'done');

    expect($after['answer'])->toContain('I added the book.')
        ->and($after['pending'])->toBe([])
        ->and(TestBook::query()->sole()->title)->toBe('Statuti del comune');
});

it('discards a write the user rejects', function (): void {
    scriptAgent([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune']),
        'Understood, I will not add it.',
    ]);

    $done = lastEvent(eventsOf($this->post('/rag/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
        'mode' => 'agent',
    ])), 'done');

    $after = lastEvent(eventsOf($this->post("/rag/chat/a/{$done['conversation']}/decisions", [
        'decisions' => ['call_1' => false],
    ])), 'done');

    expect($after['answer'])->toContain('Understood, I will not add it.')
        ->and(TestBook::query()->count())->toBe(0);
});

it('refuses a new question while a change awaits a decision', function (): void {
    scriptAgent([new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune'])]);

    $done = lastEvent(eventsOf($this->post('/rag/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
        'mode' => 'agent',
    ])), 'done');

    $this->postJson('/rag/chat/ask', [
        'question' => 'Never mind',
        'mode' => 'agent',
        'conversation' => $done['conversation'],
    ])
        ->assertStatus(409)
        ->assertJsonPath('pending.0.id', 'call_1');
});

it('will not resume on a decision for a call that is not waiting', function (): void {
    scriptAgent([new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune'])]);

    $done = lastEvent(eventsOf($this->post('/rag/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
        'mode' => 'agent',
    ])), 'done');

    // Answering something else leaves the real call undecided: nothing runs.
    $this->postJson("/rag/chat/a/{$done['conversation']}/decisions", ['decisions' => ['call_forged' => true]])
        ->assertStatus(409);

    expect(TestBook::query()->count())->toBe(0);
});

it('keeps other people out of an agent conversation', function (): void {
    $foreign = Conversation::query()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => 'App\\Models\\User',
        'participant_id' => 999,
        'title' => 'Someone else',
    ]);

    $this->getJson("/rag/chat/a/{$foreign->id}/messages")->assertNotFound();
    $this->postJson("/rag/chat/a/{$foreign->id}/decisions", ['decisions' => ['call_1' => true]])->assertNotFound();
});

it('reads an agent conversation back, pause included', function (): void {
    scriptAgent([new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune'])]);

    $done = lastEvent(eventsOf($this->post('/rag/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
        'mode' => 'agent',
    ])), 'done');

    $this->getJson("/rag/chat/a/{$done['conversation']}/messages")
        ->assertOk()
        ->assertJsonPath('mode', 'agent')
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('pending.0.id', 'call_1');
});

it('answers from the knowledge pipeline when the agent is not allowed', function (): void {
    config()->set('rag.chat.abilities.agent', false);
    config()->set('rag.answering.stream', false);

    scriptAgent(['This must not be called.']);

    // The mode is dropped before validation, so the question goes to the
    // pipeline: no agent conversation is ever created.
    $this->postJson('/rag/chat/ask', ['question' => 'How many books are there?', 'mode' => 'agent'])
        ->assertOk();

    expect(Conversation::query()->count())->toBe(0);
});
