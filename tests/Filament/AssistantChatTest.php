<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall;
use Livewire\Livewire;
use Murkrow\FilamentAi\Agent\Chat\PageContext;
use Murkrow\FilamentAi\Filament\Pages\AssistantChat;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * The chat page end to end. Like AgentApprovalFlowTest, responses are scripted
 * on the text provider rather than through PanelAssistant::fake(), which would
 * skip approval resumption.
 */

/**
 * @param  list<mixed>  $responses
 */
function scriptChat(array $responses): void
{
    Ai::textProvider()->useTextGateway(new FakeTextGateway($responses));
}

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

it('answers a question and keeps the conversation', function (): void {
    scriptChat(['There are no books yet.']);

    $component = Livewire::test(AssistantChat::class)
        ->set('prompt', 'How many books are there?')
        ->call('send')
        ->assertSet('error', null)
        ->assertSet('prompt', '')
        ->assertSee('How many books are there?')
        ->assertSee('There are no books yet.');

    expect($component->get('conversationId'))->not->toBeNull()
        ->and(Conversation::query()->count())->toBe(1);
});

it('asks for approval before a write and applies it once approved', function (): void {
    scriptChat([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune']),
        'I added the book.',
    ]);

    $component = Livewire::test(AssistantChat::class)
        ->set('prompt', 'Add a book called Statuti del comune')
        ->call('send')
        ->assertSet('error', null)
        ->assertSee('Approve this change?')
        ->assertSee('Create test book -- Title: Statuti del comune');

    expect(TestBook::query()->count())->toBe(0);

    $component->call('decide', 'call_1', true)
        ->assertSet('error', null)
        ->assertSee('I added the book.')
        ->assertDontSee('Approve this change?');

    expect(TestBook::query()->sole()->title)->toBe('Statuti del comune');
});

it('renders approval buttons the browser can actually run', function (): void {
    scriptChat([new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune'])]);

    $html = Livewire::test(AssistantChat::class)
        ->set('prompt', 'Add a book called Statuti del comune')
        ->call('send')
        ->html();

    // Blade compiles no directives inside a component tag's attributes: an
    // @js() there reached the browser verbatim and Livewire refused the
    // expression with "illegal character U+0040".
    expect($html)->toContain("decide('call_1', true)")
        ->toContain("decide('call_1', false)")
        ->not->toContain('@js(');
});

it('discards a write the user rejects', function (): void {
    scriptChat([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune']),
        'Understood, I will not add it.',
    ]);

    Livewire::test(AssistantChat::class)
        ->set('prompt', 'Add a book called Statuti del comune')
        ->call('send')
        ->call('decide', 'call_1', false)
        ->assertSet('error', null)
        ->assertSee('Understood, I will not add it.');

    expect(TestBook::query()->count())->toBe(0);
});

it('ignores a new message while a change awaits a decision', function (): void {
    scriptChat([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune']),
    ]);

    Livewire::test(AssistantChat::class)
        ->set('prompt', 'Add a book called Statuti del comune')
        ->call('send')
        ->set('prompt', 'Never mind')
        ->call('send')
        ->assertDontSee('Never mind')
        ->assertSee('Approve this change?');
});

it('will not open a conversation that belongs to someone else', function (): void {
    $foreign = Conversation::query()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => 'App\\Models\\User',
        'participant_id' => 999,
        'title' => 'Someone else',
    ]);

    Livewire::withQueryParams(['conversation' => $foreign->id])
        ->test(AssistantChat::class)
        ->assertSet('conversationId', null)
        ->call('openChat', $foreign->id)
        ->assertSet('conversationId', null)
        ->assertDontSee('Someone else');
});

it('shows the record the chat was opened about', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    Livewire::withQueryParams(['resource' => 'test-books', 'record' => (string) $book->id])
        ->test(AssistantChat::class)
        // Two assertions rather than one: Blade encodes the quotes around the
        // title, which a single literal needle would have to mirror.
        ->assertSee('About test book')
        ->assertSee('Cronaca cittadina');
});

it('ignores a record key the user may not see', function (): void {
    Livewire::withQueryParams(['resource' => 'test-books', 'record' => '999'])
        ->test(AssistantChat::class)
        ->assertDontSee('About test book');
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
