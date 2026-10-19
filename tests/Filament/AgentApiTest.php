<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * The token API: the same turn as the browser chat, for a client with no
 * session and no CSRF token. The suite registers it behind `api` + `auth`
 * (see FilamentTestCase); a host puts `auth:sanctum` there instead.
 */

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
});

final class ApiTestAssistant extends PanelAssistant
{
    protected function persona(): string
    {
        return 'You are the API test assistant.';
    }
}

it('is off unless the host switches it on', function (): void {
    $defaults = require dirname(__DIR__, 2).'/config/filament-ai.php';

    expect($defaults['api']['enabled'])->toBeFalse()
        ->and($defaults['api']['resources'])->toBeFalse();
});

it('streams an answer to a request that has no session', function (): void {
    scriptAgent(['There are no books yet.']);

    $response = $this->post('/api/ai/ask', ['question' => 'How many books are there?'], ['Accept' => 'text/event-stream']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->headers->getCookies())->toBe([]);

    $done = lastEvent(eventsOf($response), 'done');

    expect($done['answer'])->toBe('There are no books yet.')
        ->and($done['conversation'])->toBe(Conversation::query()->sole()->id);
});

it('answers with the final document when asked not to stream', function (): void {
    scriptAgent(['There are no books yet.']);

    $this->postJson('/api/ai/ask?stream=0', ['question' => 'How many books are there?'])
        ->assertOk()
        ->assertJsonPath('answer', 'There are no books yet.')
        ->assertJsonPath('pending', [])
        ->assertJsonPath('conversation', Conversation::query()->sole()->id);
});

it('leaves the panel resources out unless configured to use them', function (): void {
    $user = Auth::user();
    $names = static fn (PanelAssistant $assistant): array => array_map(
        static fn ($tool): string => $tool->name(),
        iterator_to_array($assistant->tools(), false),
    );

    $without = app(AssistantTurn::class)->assistant($user, null, resources: false);
    $with = app(AssistantTurn::class)->assistant($user, null);

    expect($names($without))->not->toContain('test_books_list')
        ->and($names($with))->toContain('test_books_list')
        ->and($without->instructions())->not->toContain('test_books_list');
});

it('answers with the configured assistant', function (): void {
    expect(app(AssistantTurn::class)->assistant(Auth::user(), null, class: ApiTestAssistant::class, resources: false))
        ->toBeInstanceOf(ApiTestAssistant::class);
});

it('runs an approval round trip when the resources are on', function (): void {
    config()->set('filament-ai.api.resources', true);

    scriptAgent([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune']),
        'I added the book.',
    ]);

    $done = $this->postJson('/api/ai/ask?stream=0', ['question' => 'Add a book called Statuti del comune'])
        ->assertOk()
        ->assertJsonPath('pending.0.id', 'call_1')
        ->json();

    expect(TestBook::query()->count())->toBe(0);

    // A new question while the change waits is refused, with what waits.
    $this->postJson('/api/ai/ask', ['question' => 'Never mind', 'conversation' => $done['conversation']])
        ->assertStatus(409)
        ->assertJsonPath('pending.0.id', 'call_1');

    $after = lastEvent(eventsOf($this->post("/api/ai/conversations/{$done['conversation']}/decisions", [
        'decisions' => ['call_1' => true],
    ])), 'done');

    expect($after['answer'])->toContain('I added the book.')
        ->and(TestBook::query()->sole()->title)->toBe('Statuti del comune');
});

it('lists, reads, renames and deletes the user\'s own conversations', function (): void {
    scriptAgent(['There are no books yet.']);

    $id = $this->postJson('/api/ai/ask?stream=0', ['question' => 'How many books are there?'])->json('conversation');

    $this->getJson('/api/ai/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.uuid', $id);

    $this->getJson("/api/ai/conversations/{$id}")
        ->assertOk()
        ->assertJsonPath('uuid', $id)
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('messages.1.content', 'There are no books yet.');

    $this->patchJson("/api/ai/conversations/{$id}", ['title' => 'Books'])
        ->assertOk()
        ->assertJsonPath('title', 'Books');

    $this->deleteJson("/api/ai/conversations/{$id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    $this->getJson('/api/ai/conversations')->assertJsonPath('data', []);
});

it('keeps other people\'s conversations out of reach', function (): void {
    $foreign = Conversation::query()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => 'App\\Models\\User',
        'participant_id' => 999,
        'title' => 'Someone else',
    ]);

    $this->getJson('/api/ai/conversations')->assertJsonPath('data', []);
    $this->getJson("/api/ai/conversations/{$foreign->id}")->assertNotFound();
    $this->patchJson("/api/ai/conversations/{$foreign->id}", ['title' => 'Mine now'])->assertNotFound();
    $this->deleteJson("/api/ai/conversations/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/ai/conversations/{$foreign->id}/decisions", ['decisions' => ['call_1' => true]])->assertNotFound();
});

it('answers an unauthenticated stream request with a 401, not a redirect', function (): void {
    Auth::logout();

    $this->post('/api/ai/ask', ['question' => 'Hello'], ['Accept' => 'text/event-stream'])
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});

it('refuses users the host does not admit', function (): void {
    config()->set('filament-ai.api.authorize', static fn (): bool => false);

    $this->getJson('/api/ai/config')->assertForbidden();
    $this->postJson('/api/ai/ask', ['question' => 'Hello'])->assertForbidden();
});

it('answers to the agent switches too', function (): void {
    config()->set('filament-ai.agent.authorize', static fn (): bool => false);

    $this->getJson('/api/ai/conversations')->assertForbidden();
});

it('validates a question as JSON', function (): void {
    $this->post('/api/ai/ask', ['question' => ''], ['Accept' => 'text/event-stream'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('question');
});

it('tells the client what it may do before the first question', function (): void {
    config()->set('filament-ai.chat.suggestions', ['What is running out?']);
    config()->set('filament-ai.chat.voice.enabled', true);
    config()->set('filament-ai.chat.voice.engine', 'local');

    $this->getJson('/api/ai/config')
        ->assertOk()
        ->assertJsonPath('abilities.history', true)
        ->assertJsonPath('abilities.debug', false)
        ->assertJsonPath('suggestions', ['What is running out?'])
        ->assertJsonPath('voice.engine', 'local')
        ->assertJsonPath('transcribe', false)
        ->assertJsonPath('maxQuestionLength', 4000)
        ->assertJsonMissingPath('csrf');
});
