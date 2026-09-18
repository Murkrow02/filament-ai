<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Livewire\Livewire;
use Murkrow\FilamentAi\Agent\Chat\PageContext;
use Murkrow\FilamentAi\Filament\Pages\AssistantChat;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * The panel page is a shell around the package's own chat component: the same
 * markup the standalone page renders, talking to the same endpoints. So what
 * is asserted here is the shell -- access, navigation, the page context and
 * the bootstrap payload -- while the turn itself is exercised over HTTP in
 * tests/Web/AgentChatTest.php, which is where it now happens.
 */

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
});

it('is registered on the panel', function (): void {
    expect(Filament::getPanel('testing')->getPages())->toContain(AssistantChat::class);
});

it('renders the chat component', function (): void {
    $html = Livewire::test(AssistantChat::class)->assertOk()->html();

    expect($html)->toContain('id="rag-chat"')
        ->toContain('data-rag-embedded')
        ->toContain('rag-chat-payload')
        // Assets come from the package's own route, so a host with no build
        // step still gets a working page.
        ->toContain('rag-chat.css')
        ->toContain('rag-chat.js');

    $payload = payloadFrom($html);

    expect($payload['embedded'])->toBeTrue()
        ->and($payload['installed'])->toBeTrue()
        ->and($payload['endpoints']['decide'])->toContain('/decisions');
});

it('is the same component the standalone page renders', function (): void {
    $panel = Livewire::test(AssistantChat::class)->html();
    $standalone = $this->get('/rag/chat')->getContent();

    // One chat, two doors: the difference is the embedding flag and nothing
    // else about the markup.
    expect($panel)->toContain('id="rag-chat"')
        ->and($standalone)->toContain('id="rag-chat"')
        ->and(payloadFrom($panel)['embedded'])->toBeTrue()
        ->and(payloadFrom($standalone)['embedded'])->toBeFalse();
});

it('will not open a conversation that belongs to someone else', function (): void {
    $foreign = Conversation::query()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => 'App\\Models\\User',
        'participant_id' => 999,
        'title' => 'Someone else',
    ]);

    $html = Livewire::withQueryParams(['conversation' => $foreign->id])
        ->test(AssistantChat::class)
        ->assertOk()
        ->assertDontSee('Someone else')
        ->html();

    expect(payloadFrom($html)['current'])->toBeNull();
});

it('shows the record the chat was opened about', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $html = Livewire::withQueryParams(['resource' => 'test-books', 'record' => (string) $book->id])
        ->test(AssistantChat::class)
        ->assertSee('About test book')
        ->assertSee('Cronaca cittadina')
        ->html();

    $context = payloadFrom($html)['context'];

    expect($context['resource'])->toBe('test-books')
        ->and($context['record'])->toBe((string) $book->id);
});

it('ignores a record key the user may not see', function (): void {
    $html = Livewire::withQueryParams(['resource' => 'test-books', 'record' => '999'])
        ->test(AssistantChat::class)
        ->assertDontSee('About test book')
        ->html();

    // No label, no context: the id the browser sent is not passed on.
    expect(payloadFrom($html)['context']['record'])->toBeNull();
});

it('builds the topbar link from the page context', function (): void {
    expect(PageContext::parameters(TestBookResource::class, '5'))->toBe(['resource' => 'test-books', 'record' => '5'])
        ->and(PageContext::parameters(null, null))->toBe([])
        ->and(PageContext::resourceForSlug('test-books'))->toBe(TestBookResource::class);
});

it('is forbidden when the authorize callback denies', function (): void {
    config()->set('rag.agent.authorize', fn (): bool => false);

    Livewire::test(AssistantChat::class)->assertForbidden();
});

it('explains itself instead of failing when laravel/ai tables are missing', function (): void {
    Schema::drop('agent_conversation_messages');

    Livewire::test(AssistantChat::class)
        ->assertOk()
        ->assertSee('Publish and run laravel/ai');
});
