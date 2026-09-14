<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceInspector;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

class DenyTestBooksPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, TestBook $book): bool
    {
        return false;
    }
}

class ViewAnyButNotOneTestBookPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TestBook $book): bool
    {
        return $book->title !== 'Riservato';
    }
}

/**
 * @return array<string, Tool>
 */
function agentResourceTools(): array
{
    $tools = [];

    foreach (app(ResourceToolRegistry::class)->tools() as $tool) {
        $tools[$tool->name()] = $tool;
    }

    return $tools;
}

/**
 * @return array<string, mixed>
 */
function callAgentTool(string $name, array $arguments = []): array
{
    $output = agentResourceTools()[$name]->handle(new Request($arguments));

    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

it('derives tools only for resources that opted in', function (): void {
    expect(array_keys(agentResourceTools()))->toBe(['test_books_list', 'test_books_view']);
});

it('derives search columns and attributes from the table and the form', function (): void {
    $blueprint = app(ResourceInspector::class)->inspect(TestBookResource::class, new AgentTools(TestBookResource::class));

    expect($blueprint->searchColumns)->toBe(['title', 'author'])
        ->and($blueprint->listAttributes)->toBe(['title', 'author', 'created_at'])
        ->and($blueprint->viewAttributes)->toBe(['title', 'author', 'created_at'])
        ->and($blueprint->label)->toBe('test book')
        ->and($blueprint->toolPrefix)->toBe('test_books');
});

it('lists records newest first with the total count', function (): void {
    TestBook::create(['title' => 'Cronaca cittadina', 'author' => 'Anonimo']);
    TestBook::create(['title' => 'Statuti del comune', 'author' => 'Notaio Ser Piero']);

    $result = callAgentTool('test_books_list');

    expect($result['total'])->toBe(2)
        ->and(array_column($result['records'], 'title'))->toBe(['Statuti del comune', 'Cronaca cittadina'])
        ->and($result['records'][0])->toHaveKeys(['id', 'title', 'author', 'created_at']);
});

it('searches across the searchable columns', function (): void {
    TestBook::create(['title' => 'Cronaca cittadina', 'author' => 'Anonimo']);
    TestBook::create(['title' => 'Statuti del comune', 'author' => 'Notaio Ser Piero']);

    expect(callAgentTool('test_books_list', ['search' => 'piero'])['records'])->toHaveCount(1)
        ->and(callAgentTool('test_books_list', ['search' => 'statuti'])['records'][0]['title'])->toBe('Statuti del comune');
});

it('never returns more records than the cap, whatever the model asks for', function (): void {
    foreach (range(1, 30) as $i) {
        TestBook::create(['title' => "Volume {$i}"]);
    }

    $result = callAgentTool('test_books_list', ['per_page' => 500]);

    expect($result['records'])->toHaveCount(25)
        ->and($result['per_page'])->toBe(25)
        ->and($result['total'])->toBe(30);
});

it('reads one record by id', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina', 'author' => 'Anonimo']);

    $result = callAgentTool('test_books_view', ['id' => (string) $book->id]);

    expect($result)->toMatchArray(['id' => $book->id, 'title' => 'Cronaca cittadina', 'author' => 'Anonimo']);
});

it('says plainly when a record does not exist', function (): void {
    expect(agentResourceTools()['test_books_view']->handle(new Request(['id' => '999'])))
        ->toBe('Error: no test book with id [999].');
});

it('offers no tools for a resource the user may not view', function (): void {
    Gate::policy(TestBook::class, DenyTestBooksPolicy::class);

    expect(agentResourceTools())->toBe([]);
});

it('checks the policy again when a tool is called', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina']);
    $tools = agentResourceTools();

    // The tool list was built while access was allowed; the policy changes
    // before the call, as it can within one conversation.
    Gate::policy(TestBook::class, DenyTestBooksPolicy::class);

    expect($tools['test_books_list']->handle(new Request([])))->toStartWith('Error: the current user is not allowed')
        ->and($tools['test_books_view']->handle(new Request(['id' => (string) $book->id])))->toStartWith('Error: the current user is not allowed');
});

it('checks the record policy on view, not only viewAny', function (): void {
    Gate::policy(TestBook::class, ViewAnyButNotOneTestBookPolicy::class);
    $secret = TestBook::create(['title' => 'Riservato']);

    expect(agentResourceTools()['test_books_view']->handle(new Request(['id' => (string) $secret->id])))
        ->toBe('Error: the current user is not allowed to view this test book.');
});

it('never presents an attribute the model hides', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina', 'author' => 'Anonimo']);
    $book->setHidden(['author']);

    $blueprint = app(ResourceInspector::class)->inspect(TestBookResource::class, new AgentTools(TestBookResource::class));

    expect(RecordPresenter::present($book, $blueprint, ['title', 'author']))->not->toHaveKey('author');
});

it('narrows abilities and rejects unknown ones', function (): void {
    $tools = new AgentTools(TestBookResource::class);

    expect($tools->except(AgentTools::VIEW)->abilities())->toBe([AgentTools::LIST])
        ->and((new AgentTools(TestBookResource::class))->only()->abilities())->toBe([]);

    (new AgentTools(TestBookResource::class))->only('delete');
})->throws(InvalidArgumentException::class);
