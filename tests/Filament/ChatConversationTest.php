<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/*
 * Threads belong to laravel/ai's store -- the assistant's memory, and the only
 * place a turn paused for approval can resume from -- so the sidebar's
 * rename, delete and reopen go through it.
 */

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
});

function startedThread(): string
{
    scriptAgent(['Ci sono tre libri.']);

    $response = test()->post('/ai/chat/ask', ['question' => 'Quanti libri ci sono?']);
    $response->assertOk();
    $response->streamedContent();

    return Conversation::query()->sole()->id;
}

it('reopens a thread with its turns', function (): void {
    $conversation = startedThread();

    $this->getJson('/ai/chat/c/'.$conversation.'/messages')
        ->assertOk()
        ->assertJsonPath('uuid', $conversation)
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('messages.0.content', 'Quanti libri ci sono?')
        ->assertJsonPath('messages.1.content', 'Ci sono tre libri.');
});

it('renames and deletes a thread', function (): void {
    $conversation = startedThread();

    $this->patchJson('/ai/chat/c/'.$conversation, ['title' => 'I libri'])
        ->assertOk()
        ->assertJsonPath('title', 'I libri');

    $this->deleteJson('/ai/chat/c/'.$conversation)->assertOk();

    // The messages go with it: an orphaned transcript would keep the answers
    // alive after the user deleted the thread they belonged to.
    expect(Conversation::query()->count())->toBe(0)
        ->and(ConversationMessage::query()->count())->toBe(0);
});

it('refuses to rename or delete without the delete ability', function (): void {
    $conversation = startedThread();

    config()->set('filament-ai.chat.abilities.delete', false);

    $this->patchJson('/ai/chat/c/'.$conversation, ['title' => 'Nope'])->assertForbidden();
    $this->deleteJson('/ai/chat/c/'.$conversation)->assertForbidden();

    expect(Conversation::query()->count())->toBe(1);
});

it('will not touch a thread that belongs to somebody else', function (): void {
    $foreign = Conversation::query()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => 'App\\Models\\User',
        'participant_id' => 999,
        'title' => 'Someone else',
    ]);

    $this->getJson('/ai/chat/c/'.$foreign->id.'/messages')->assertNotFound();
    $this->patchJson('/ai/chat/c/'.$foreign->id, ['title' => 'Mine now'])->assertNotFound();
    $this->deleteJson('/ai/chat/c/'.$foreign->id)->assertNotFound();

    expect(Conversation::query()->whereKey($foreign->id)->value('title'))->toBe('Someone else');
});

it('lists the threads the signed-in user owns', function (): void {
    $conversation = startedThread();

    $payload = payloadFrom($this->get('/ai/chat')->getContent());

    expect($payload['conversations'])->toHaveCount(1)
        ->and($payload['conversations'][0]['uuid'])->toBe($conversation);
});
