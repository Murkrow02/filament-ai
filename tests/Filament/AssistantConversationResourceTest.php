<?php

declare(strict_types=1);

use Laravel\Ai\Responses\Data\ToolCall;
use Livewire\Livewire;
use Murkrow\FilamentAi\Agent\Chat\StoredConversation;
use Murkrow\FilamentAi\Filament\Resources\AssistantConversationResource;
use Murkrow\FilamentAi\Filament\Resources\AssistantConversationResource\Pages\ListAssistantConversations;
use Murkrow\FilamentAi\Filament\Resources\AssistantConversationResource\Pages\ViewAssistantConversation;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);

    TestBook::query()->create(['title' => 'Statuti']);

    scriptAgent([
        new ToolCall('call_0', 'test_books_list', ['search' => 'Statuti']),
        'Ho trovato **Statuti**.',
    ]);

    $this->conversation = lastEvent(eventsOf($this->post('/ai/chat/ask', ['question' => 'Cerca gli statuti'])), 'done')['conversation'];
});

it('lists every conversation, with who had it', function (): void {
    Livewire::test(ListAssistantConversations::class)
        ->assertOk()
        ->assertCanSeeTableRecords([StoredConversation::query()->findOrFail($this->conversation)])
        ->assertSee('Panel user');
});

it('shows a conversation turn by turn, with the tools it used', function (): void {
    Livewire::test(ViewAssistantConversation::class, ['record' => $this->conversation])
        ->assertOk()
        ->assertSee('Cerca gli statuti')
        ->assertSeeHtml('<strong>Statuti</strong>')
        ->assertSee('test_books_list')
        ->assertSee('Search test books');
});

it('is read-only', function (): void {
    $record = StoredConversation::query()->findOrFail($this->conversation);

    expect(AssistantConversationResource::canCreate())->toBeFalse()
        ->and(AssistantConversationResource::canEdit($record))->toBeFalse()
        ->and(AssistantConversationResource::canDelete($record))->toBeFalse();
});

it('is closed to whoever may not open the knowledge pages', function (): void {
    config()->set('filament-ai.filament.authorize', fn (): bool => false);

    expect(AssistantConversationResource::canAccess())->toBeFalse();
});

it('escapes what the model wrote', function (): void {
    scriptAgent(['<script>alert(1)</script> [x](javascript:alert(2))']);

    $conversation = lastEvent(eventsOf($this->post('/ai/chat/ask', ['question' => 'Prova'])), 'done')['conversation'];

    Livewire::test(ViewAssistantConversation::class, ['record' => $conversation])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('href="javascript:');
});
