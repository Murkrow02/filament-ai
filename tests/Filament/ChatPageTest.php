<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Murkrow\FilamentAi\Chat\ChatAbilities;

/*
 * The standalone chat page and the endpoints behind it.
 *
 * They are the same endpoints the panel's page uses -- one chat, two doors --
 * so switching the standalone page off has to leave the panel's working.
 */

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
});

it('serves the page to a signed-in user', function (): void {
    $this->get('/ai/chat')
        ->assertOk()
        ->assertSee('fai-chat-payload', escape: false)
        ->assertSee('filament-ai-chat.js', escape: false);
});

it('reports the page as missing once it is switched off', function (): void {
    // The routes are bound at boot and stay bound; the check happens when the
    // request arrives, so an application that flips the setting at runtime --
    // from the settings page, say -- sees it take effect at once. 404 rather
    // than 403: a disabled feature is not a permission problem.
    config()->set('filament-ai.chat.enabled', false);

    $this->get('/ai/chat')->assertNotFound();
});

it('keeps the endpoints up for the panel chat when only the page is off', function (): void {
    config()->set('filament-ai.chat.enabled', false);
    config()->set('filament-ai.agent.chat.enabled', true);

    // Not a 404: the panel's chat talks to this, and a missing thread is the
    // only reason it may answer one.
    $this->getJson('/ai/chat/c/'.Str::uuid7().'/messages')->assertNotFound();
    $this->postJson('/ai/chat/ask', ['question' => 'Quanti libri ci sono?'])->assertOk();
});

it('reports every route as missing when both chats are off', function (): void {
    config()->set('filament-ai.chat.enabled', false);
    config()->set('filament-ai.agent.chat.enabled', false);

    $this->get('/ai/chat')->assertNotFound();
    $this->postJson('/ai/chat/ask', ['question' => 'Quanti libri ci sono?'])->assertNotFound();
});

it('refuses every route when the view ability is denied', function (): void {
    config()->set('filament-ai.chat.abilities.view', false);

    $this->get('/ai/chat')->assertForbidden();
    $this->postJson('/ai/chat/ask', ['question' => 'Quanti libri ci sono?'])->assertForbidden();
});

it('hides the settings panel when the ability is denied', function (): void {
    config()->set('filament-ai.llm.available_models', ['gpt-4o-mini' => 'Small']);

    expect($this->get('/ai/chat')->getContent())->toContain('id="fai-settings"');

    config()->set('filament-ai.chat.abilities.settings', false);

    expect($this->get('/ai/chat')->getContent())->not->toContain('id="fai-settings"');
});

it('hides the model picker when the ability is denied', function (): void {
    config()->set('filament-ai.llm.available_models', ['gpt-4o-mini' => 'Small']);
    config()->set('filament-ai.chat.abilities.model', false);

    $html = $this->get('/ai/chat')->getContent();

    expect($html)->not->toContain('id="fai-set-model"')
        ->and(payloadFrom($html)['models'])->toBe([]);
});

it('ignores a model the caller may not choose', function (): void {
    config()->set('filament-ai.llm.available_models', ['other-model' => 'Other']);
    config()->set('filament-ai.chat.abilities.model', false);

    scriptAgent(['Una risposta.']);

    $response = $this->post('/ai/chat/ask', ['question' => 'Quanti libri ci sono?', 'model' => 'other-model']);
    $response->assertOk();

    // A model this user may not choose is dropped before validation, so the
    // question is answered with the configured one rather than refused.
    expect($response->streamedContent())->toContain('event: done')
        ->and(Conversation::query()->count())->toBe(1);
});

it('rejects a model that is not on the list', function (): void {
    config()->set('filament-ai.llm.available_models', ['gpt-4o-mini' => 'Small']);

    $this->postJson('/ai/chat/ask', ['question' => 'Quanti libri ci sono?', 'model' => 'made-up'])
        ->assertStatus(422);
});

it('registers every ability as a gate', function (): void {
    foreach (ChatAbilities::names() as $name) {
        expect(Gate::has(ChatAbilities::ability($name)))->toBeTrue();
    }
});
