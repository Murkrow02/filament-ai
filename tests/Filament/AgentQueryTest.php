<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Responses\Data\ToolCall;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;
use Murkrow\FilamentAi\Tests\Fixtures\TestUser;

/*
 * `agentQuery()` keeps records the panel shows away from the agent, and the
 * token API can give the resource tools to users with no panel login.
 */

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);

    TestBook::query()->create(['title' => 'Statuti del comune', 'author' => 'Anonimo']);
    TestBook::query()->create(['title' => 'Libro segreto', 'author' => 'Ser Piero']);

    TestBookResource::$agentQueryUsing = static fn (Builder $query): Builder => $query->where('title', '!=', 'Libro segreto');
});

afterEach(function (): void {
    TestBookResource::$agentQueryUsing = null;
});

it('lists and views through agentQuery', function (): void {
    $secret = TestBook::query()->where('title', 'Libro segreto')->sole();
    scriptAgent([
        new ToolCall('call_1', 'test_books_list', []),
        new ToolCall('call_2', 'test_books_view', ['id' => (string) $secret->id]),
        'Done.',
    ]);

    $this->post('/ai/chat/ask', ['question' => 'Books?'])->assertOk()->streamedContent();

    $steps = \Laravel\Ai\Models\ConversationMessage::query()->get()->pluck('tool_results')->flatten(1)->filter()->values();
    $results = json_encode($steps);

    expect($results)->toContain('Statuti del comune')
        ->and($results)->not->toContain('Libro segreto')
        ->and($results)->toContain('no test book with id');
})->skip(fn () => ! class_exists(\Laravel\Ai\Models\ConversationMessage::class));

it('applies agentQuery to the record on the page', function (): void {
    $secret = TestBook::query()->where('title', 'Libro segreto')->sole();

    $context = app(AssistantTurn::class)->resolvedContext('test-books', (string) $secret->id);

    expect($context['record'])->toBeNull();
});

it('gives API users without a panel login the resource tools when allowed', function (): void {
    TestUser::$panelAccess = false;
    config()->set('filament-ai.api.resources', true);

    config()->set('filament-ai.api.require_panel_access', false);
    $names = fn () => array_map(
        static fn ($tool): string => $tool->name(),
        iterator_to_array(app(AssistantTurn::class)->assistant(Auth::user(), null)->tools(), false),
    );

    // Outside an API request the panel gate still applies.
    expect($names())->not->toContain('test_books_list');

    request()->attributes->set(\Murkrow\FilamentAi\Http\Middleware\AuthorizeApi::ATTRIBUTE, true);
    app(\Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry::class)->forget();

    expect($names())->toContain('test_books_list');

    TestUser::$panelAccess = true;
});
