<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * The chat, over the wire the page actually uses.
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

it('streams an agent answer and keeps the conversation', function (): void {
    scriptAgent(['There are no books yet.']);

    $response = $this->post('/ai/chat/ask', ['question' => 'How many books are there?']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $events = eventsOf($response);
    $done = lastEvent($events, 'done');

    expect(array_column($events, 'event'))->toContain('start', 'delta', 'done')
        ->and($done['answer'])->toBe('There are no books yet.')
        ->and($done['pending'])->toBe([])
        ->and($done['conversation'])->toBe(Conversation::query()->sole()->id);
});

it('asks for approval before a write and applies it once approved', function (): void {
    scriptAgent([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune']),
        'I added the book.',
    ]);

    $events = eventsOf($this->post('/ai/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
    ]));

    $approval = lastEvent($events, 'approval');
    $done = lastEvent($events, 'done');

    $card = [
        'title' => 'Create test book',
        'record' => null,
        // What is being approved, in the form's own words; without it the
        // button asks for a blank cheque.
        'changes' => [['label' => 'Title', 'before' => null, 'after' => 'Statuti del comune']],
        'summary' => null,
        'id' => 'call_1',
    ];

    expect($approval['calls'][0])->toBe($card)
        // A reload draws the same card as the stream did.
        ->and($done['pending'][0])->toBe($card)
        ->and(TestBook::query()->count())->toBe(0);

    $resumed = eventsOf($this->post("/ai/chat/c/{$done['conversation']}/decisions", [
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

    $done = lastEvent(eventsOf($this->post('/ai/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
    ])), 'done');

    $after = lastEvent(eventsOf($this->post("/ai/chat/c/{$done['conversation']}/decisions", [
        'decisions' => ['call_1' => false],
    ])), 'done');

    expect($after['answer'])->toContain('Understood, I will not add it.')
        ->and(TestBook::query()->count())->toBe(0);
});

it('refuses a new question while a change awaits a decision', function (): void {
    scriptAgent([new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune'])]);

    $done = lastEvent(eventsOf($this->post('/ai/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
    ])), 'done');

    $this->postJson('/ai/chat/ask', [
        'question' => 'Never mind',
        'conversation' => $done['conversation'],
    ])
        ->assertStatus(409)
        ->assertJsonPath('pending.0.id', 'call_1');
});

it('will not resume on a decision for a call that is not waiting', function (): void {
    scriptAgent([new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune'])]);

    $done = lastEvent(eventsOf($this->post('/ai/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
    ])), 'done');

    // Answering something else leaves the real call undecided: nothing runs.
    $this->postJson("/ai/chat/c/{$done['conversation']}/decisions", ['decisions' => ['call_forged' => true]])
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

    $this->getJson("/ai/chat/c/{$foreign->id}/messages")->assertNotFound();
    $this->postJson("/ai/chat/c/{$foreign->id}/decisions", ['decisions' => ['call_1' => true]])->assertNotFound();
});

it('reads an agent conversation back, pause included', function (): void {
    scriptAgent([new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune'])]);

    $done = lastEvent(eventsOf($this->post('/ai/chat/ask', [
        'question' => 'Add a book called Statuti del comune',
    ])), 'done');

    $this->getJson("/ai/chat/c/{$done['conversation']}/messages")
        ->assertOk()
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('pending.0.id', 'call_1');
});

it('says so instead of failing when laravel/ai is not installed', function (): void {
    Schema::drop('agent_conversation_messages');

    scriptAgent(['This must not be called.']);

    $this->postJson('/ai/chat/ask', ['question' => 'How many books are there?'])
        ->assertStatus(409)
        // The reader cannot run a migration: they are told who can help, and
        // only the debug ability gets the how.
        ->assertJsonPath('message', __('filament-ai::messages.assistant.unavailable'))
        ->assertJsonMissingPath('detail');
});

it('streams the passages a citation points at, and keeps them for a reload', function (): void {
    config()->set('filament-ai.chunking.target_tokens', 30);
    config()->set('filament-ai.chunking.overlap_tokens', 0);
    config()->set('filament-ai.chunking.min_tokens', 0);

    $book = TestBook::create(['title' => 'Cronaca cittadina']);
    $book->pages()->create([
        'number' => 7,
        'content' => 'Il podesta Guido Novello convoco il consiglio generale nel mese di marzo.',
    ]);

    FilamentAi::ingestSync('books');

    scriptAgent([
        new ToolCall('call_1', 'search_knowledge', ['query' => 'chi convoco il consiglio']),
        'Lo convoco il podesta Guido Novello [#1].',
    ]);

    $events = eventsOf($this->post('/ai/chat/ask', ['question' => 'Chi convoco il consiglio?']));

    $sources = lastEvent($events, 'sources');
    $done = lastEvent($events, 'done');

    expect($sources['passages'][0]['marker'])->toBe(1)
        ->and($sources['passages'][0]['label'])->toContain('Cronaca cittadina')
        ->and($done['passages'])->toHaveCount(count($sources['passages']))
        // The marker in the answer is the page's link to the passage, so it
        // must survive into the text the page renders.
        ->and($done['answer'])->toContain('[#1]');

    // Reopened later, the turn still shows what it cited: the passages are
    // read back out of the tool output laravel/ai stored.
    $reopened = $this->getJson('/ai/chat/c/'.$done['conversation'].'/messages')->assertOk()->json();

    $passages = collect($reopened['messages'])->pluck('passages')->filter()->flatten(1)->all();

    expect($passages)->not->toBeEmpty()
        ->and($passages[0]['marker'])->toBe(1)
        ->and($passages[0]['label'])->toContain('Cronaca cittadina')
        ->and($passages[0]['content'])->toContain('consiglio');
});
