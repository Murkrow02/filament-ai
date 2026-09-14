<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Facades\Rag;
use Murkrow\FilamentAi\Models\Conversation;
use Murkrow\FilamentAi\Models\QueryLog;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedChatCorpus(): void
{
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $book->pages()->create([
        'number' => 7,
        'content' => 'Il podesta Guido Novello convoco il consiglio generale nel mese di marzo. La delibera fu approvata a maggioranza.',
    ]);

    Rag::ingestSync('books');
}

it('serves the page to a logged-in user', function (): void {
    $this->actingAs($this->user());

    $this->get('/rag/chat')
        ->assertOk()
        ->assertSee('rag-chat-payload', escape: false)
        ->assertSee('rag-chat.js', escape: false);
});

it('reports the chat as missing once it is switched off', function (): void {
    // The routes were bound at boot and stay bound; the guard middleware is
    // what turns the page off for an application that flips the flag later,
    // and it reports 404 rather than 403 -- a disabled feature is not a
    // permission problem.
    config()->set('rag.chat.enabled', false);

    $this->actingAs($this->user());

    $this->get('/rag/chat')->assertNotFound();
    $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])->assertNotFound();
});

it('refuses every route when the view ability is denied', function (): void {
    config()->set('rag.chat.abilities.view', false);

    $this->actingAs($this->user());

    $this->get('/rag/chat')->assertForbidden();
    $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])->assertForbidden();
    $this->postJson('/rag/chat/conversations')->assertForbidden();
});

it('starts a conversation on the first question and titles it', function (): void {
    seedChatCorpus();
    $this->actingAs($user = $this->user());

    $response = $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio generale?'])
        ->assertOk();

    $conversation = Conversation::query()->firstOrFail();

    expect($conversation->user_id)->toBe((string) $user->getAuthIdentifier())
        ->and($conversation->title)->toBe('Chi convoco il consiglio generale?')
        ->and($conversation->turns)->toBe(1)
        ->and($conversation->last_message_at)->not->toBeNull();

    $query = QueryLog::query()->firstOrFail();

    expect($query->conversation_id)->toBe($conversation->id)
        ->and($query->turn)->toBe(1)
        ->and($query->channel->value)->toBe('web');

    expect($response->json('conversation'))->toBe($conversation->uuid);
});

it('replays previous turns into the prompt', function (): void {
    seedChatCorpus();
    $this->actingAs($this->user());

    $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])->assertOk();

    $uuid = Conversation::query()->firstOrFail()->uuid;

    $this->postJson('/rag/chat/ask', [
        'question' => 'E quando?',
        'conversation' => $uuid,
    ])->assertOk();

    $prompts = app(\Murkrow\FilamentAi\Contracts\LanguageModel::class)->received();

    expect($prompts)->toHaveCount(2)
        ->and($prompts[1]['user'])->toContain('Chi convoco il consiglio?');

    expect(QueryLog::query()->orderByDesc('id')->first()->turn)->toBe(2);
});

it('keeps the conversation cost in step with its turns', function (): void {
    seedChatCorpus();
    $this->actingAs($this->user());

    $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])->assertOk();

    $conversation = Conversation::query()->firstOrFail();
    $query = QueryLog::query()->firstOrFail();

    expect($conversation->cost_micros)->toBe($query->cost_micros);
});
