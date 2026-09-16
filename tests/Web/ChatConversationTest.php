<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Facades\Rag;
use Murkrow\FilamentAi\Models\Conversation;
use Murkrow\FilamentAi\Models\QueryLog;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedConversationCorpus(): void
{
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $book->pages()->create([
        'number' => 7,
        'content' => 'Il podesta Guido Novello convoco il consiglio generale nel mese di marzo.',
    ]);

    Rag::ingestSync('books');
}

it('reopens a conversation with its turns and stored passages', function (): void {
    seedConversationCorpus();
    $this->actingAs($this->user());

    $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])->assertOk();

    $conversation = Conversation::query()->firstOrFail();

    $response = $this->getJson('/rag/chat/c/'.$conversation->uuid.'/messages')->assertOk();

    expect($response->json('messages'))->toHaveCount(1)
        ->and($response->json('messages.0.question'))->toBe('Chi convoco il consiglio?')
        ->and($response->json('messages.0.passages'))->not->toBeEmpty()
        ->and($response->json('messages.0.passages.0.label'))->toContain('Cronaca cittadina');
});

it('renames, pins and deletes a conversation', function (): void {
    seedConversationCorpus();
    $this->actingAs($this->user());

    $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])->assertOk();

    $conversation = Conversation::query()->firstOrFail();

    $this->patchJson('/rag/chat/c/'.$conversation->uuid, ['title' => 'Il consiglio'])
        ->assertOk()
        ->assertJsonPath('title', 'Il consiglio');

    $this->patchJson('/rag/chat/c/'.$conversation->uuid, ['pinned' => true])
        ->assertOk()
        ->assertJsonPath('pinned', true);

    $this->deleteJson('/rag/chat/c/'.$conversation->uuid)->assertOk();

    // The turns go with it: an orphaned query row would keep the answer alive
    // after the user deleted the thread it belonged to.
    expect(Conversation::query()->count())->toBe(0)
        ->and(QueryLog::query()->count())->toBe(0);
});

it('refuses to rename or delete without the delete ability', function (): void {
    config()->set('rag.chat.abilities.delete', false);

    $this->actingAs($this->user());

    $conversation = Conversation::query()->create([
        'user_id' => (string) auth()->id(),
        'title' => 'Mine',
    ]);

    $this->patchJson('/rag/chat/c/'.$conversation->uuid, ['title' => 'x'])->assertForbidden();
    $this->deleteJson('/rag/chat/c/'.$conversation->uuid)->assertForbidden();
});

it('records feedback on an answer', function (): void {
    seedConversationCorpus();
    $this->actingAs($this->user());

    $response = $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])->assertOk();

    $this->postJson('/rag/chat/m/'.$response->json('id').'/feedback', ['feedback' => 1])
        ->assertOk()
        ->assertJsonPath('feedback', 1);

    expect(QueryLog::query()->firstOrFail()->feedback)->toBe(1);
});

it('will not record feedback on somebody else\'s answer', function (): void {
    seedConversationCorpus();

    $this->actingAs($this->user('first@example.test'));
    $response = $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])->assertOk();

    $this->actingAs($this->user('second@example.test'));

    $this->postJson('/rag/chat/m/'.$response->json('id').'/feedback', ['feedback' => -1])
        ->assertNotFound();
});

it('answers without persisting anything when the query log is off', function (): void {
    seedConversationCorpus();
    config()->set('rag.retrieval.log_queries', false);

    $this->actingAs($this->user());

    $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])
        ->assertOk()
        ->assertJsonPath('conversation', null);

    expect(Conversation::query()->count())->toBe(0)
        ->and(QueryLog::query()->count())->toBe(0);

    // The sidebar is switched off rather than shown permanently empty.
    $this->get('/rag/chat')->assertOk()->assertSee('"persist":false', escape: false);
});

it('prunes the oldest conversations past the configured cap', function (): void {
    config()->set('rag.chat.max_conversations', 2);

    $this->actingAs($this->user());

    foreach (range(1, 4) as $index) {
        $this->postJson('/rag/chat/conversations')->assertCreated();

        Conversation::query()->latest('id')->first()->forceFill([
            'last_message_at' => now()->subMinutes(10 - $index),
        ])->save();
    }

    expect(Conversation::query()->count())->toBeLessThanOrEqual(3);
});

it('starts a new thread instead of failing when the conversation id is unusable', function (): void {
    seedConversationCorpus();
    $this->actingAs($this->user());

    // A corrupted or stale id is a reason to open a new thread, never a reason
    // to refuse the question -- otherwise a bad value in the page strands the
    // user with no way to ask anything at all.
    foreach (['undefined', 'null', 'not-a-uuid', '0e2a9c02-0000-0000-0000-000000000000'] as $stale) {
        $this->postJson('/rag/chat/ask', [
            'question' => 'Chi convoco il consiglio?',
            'conversation' => $stale,
        ])->assertOk()->assertJsonPath('refused', false);
    }

    expect(Murkrow\FilamentAi\Models\Conversation::query()->count())->toBe(4);
});
