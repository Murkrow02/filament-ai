<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Chat\PanelScope;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceInspector;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Murkrow\FilamentAi\Agent\Solving\SolveScope;
use Murkrow\FilamentAi\Models\SolveRun;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestArticleResource;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestArticle;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
});

/**
 * @return array<string, Tool>
 */
function toolsOf(string $resource, ?AgentTools $tools = null): array
{
    $blueprint = app(ResourceInspector::class)->inspect($resource, $tools ?? $resource::agentTools(new AgentTools($resource)));
    $named = [];

    foreach (app(ResourceToolRegistry::class)->toolsFor($blueprint) as $tool) {
        $named[$tool->name()] = $tool;
    }

    return $named;
}

it('runs an approved write once, however many times the decision arrives', function (): void {
    scriptAgent([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti']),
        'Added.',
    ]);

    $done = lastEvent(eventsOf($this->post('/ai/chat/ask', ['question' => 'Add Statuti'])), 'done');

    // Another request is still resuming this turn.
    $held = Cache::lock('filament-ai:turn:'.$done['conversation'], 60);
    $held->get();

    $this->postJson("/ai/chat/c/{$done['conversation']}/decisions", ['decisions' => ['call_1' => true]])
        ->assertStatus(409);

    $held->release();

    eventsOf($this->post("/ai/chat/c/{$done['conversation']}/decisions", ['decisions' => ['call_1' => true]]));

    $this->postJson("/ai/chat/c/{$done['conversation']}/decisions", ['decisions' => ['call_1' => true]])
        ->assertStatus(409);

    expect(TestBook::query()->count())->toBe(1);
});

it('resumes a turn that read before it paused on a write', function (): void {
    // laravel/ai 0.11 replayed this shape with an orphaned tool result that
    // Anthropic rejected -- after the approved write had already run. 1.0
    // stores each step with its own results; this pins that it stays fixed.
    scriptAgent([
        new ToolCall('call_0', 'test_books_list', []),
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti']),
        'Added.',
    ]);

    $done = lastEvent(eventsOf($this->post('/ai/chat/ask', ['question' => 'Add Statuti if missing'])), 'done');

    expect($done['pending'][0]['id'])->toBe('call_1');

    $after = lastEvent(eventsOf($this->post("/ai/chat/c/{$done['conversation']}/decisions", [
        'decisions' => ['call_1' => true],
    ])), 'done');

    expect($after['answer'])->toContain('Added.')
        ->and(TestBook::query()->sole()->title)->toBe('Statuti');
});

it('never puts a write the form refuses to the user, and never runs it', function (): void {
    $tool = toolsOf(TestBookResource::class)['test_books_create'];
    $request = new Request(['author' => 'Anonimo']);

    expect($tool->shouldRequestApproval($request))->toBeNull()
        ->and($tool->handle($request))->toStartWith('Error:')->toContain('[title]')
        ->and(TestBook::query()->count())->toBe(0);
});

it('shows the change an edit makes, with labels and readable values', function (): void {
    $article = TestArticle::query()->create(['title' => 'Vecchio', 'status' => 'draft']);

    $approval = toolsOf(TestArticleResource::class)['test_articles_edit']
        ->shouldRequestApproval(new Request(['id' => (string) $article->id, 'status' => 'published']));

    expect($approval->reason)->toBe('Edit test article «Vecchio» — Status: Bozza → Pubblicato');
});

it('keeps an exception away from the model and reports it with a reference', function (): void {
    $tool = toolsOf(TestBookResource::class)['test_books_list'];

    Schema::drop('test_books');

    $output = $tool->handle(new Request([]));

    expect($output)->toStartWith('Error:')
        ->toContain('reference')
        ->not->toContain('SQLSTATE')
        ->not->toContain('test_books');
});

it('refuses an id of the wrong shape before it reaches the database', function (): void {
    expect(toolsOf(TestBookResource::class)['test_books_view']->handle(new Request(['id' => 'Statuti del comune'])))
        ->toStartWith('Error:')
        ->toContain('not a valid');
});

it('searches for the text typed, not for a pattern', function (): void {
    TestBook::query()->create(['title' => 'a_b']);
    TestBook::query()->create(['title' => 'axb']);
    TestBook::query()->create(['title' => '100%']);

    $search = fn (string $text): array => array_column(
        json_decode(toolsOf(TestBookResource::class)['test_books_list']->handle(new Request(['search' => $text])), true)['records'],
        'title',
    );

    expect($search('a_b'))->toBe(['a_b'])
        ->and($search('100%'))->toBe(['100%']);
});

it('puts a solving attempt back in the panel and user it was started by', function (): void {
    PanelScope::enter(Filament::getPanel('testing'), auth()->user());

    $scope = SolveScope::capture();
    $user = auth()->user();

    expect($scope)->toBe(['panel' => 'testing', 'tenant' => null, 'user' => $user->getKey()]);

    auth()->forgetGuards();

    SolveScope::restore(new SolveRun(['scope' => $scope]));

    expect(auth()->id())->toBe($user->getKey())
        ->and(app(ResourceToolRegistry::class)->tools())->not->toBe([]);
});

it('never presents what a related model hides', function (): void {
    $book = TestBook::query()->create(['title' => 'Statuti']);
    $article = TestArticle::query()->create(['title' => 'A', 'status' => 'draft', 'password' => 'secret-hash', 'test_book_id' => $book->id]);

    $blueprint = app(ResourceInspector::class)->inspect(
        TestBookResource::class,
        (new AgentTools(TestBookResource::class))->attributes(['title']),
    );

    $hidden = new class extends TestArticle
    {
        protected $visible = ['title'];
    };

    $presented = RecordPresenter::present(
        $hidden->newQuery()->find($article->id),
        $blueprint,
        ['title', 'status', 'password', 'book.title'],
        withUrl: false,
    );

    expect($presented)->toHaveKey('title')
        ->not->toHaveKey('status')
        ->not->toHaveKey('password');

    $viaBook = RecordPresenter::present(
        TestArticle::query()->find($article->id),
        $blueprint,
        ['title', 'password', 'book.title'],
        withUrl: false,
    );

    expect($viaBook)->toHaveKey('book.title', 'Statuti')->not->toHaveKey('password');
});

it('skips a column it cannot read instead of failing the whole list', function (): void {
    TestBook::query()->create(['title' => 'Statuti']);

    $model = new class extends TestBook
    {
        // A method named like a column, that is not a relation.
        public function progress(): int
        {
            return 50;
        }
    };

    $blueprint = app(ResourceInspector::class)->inspect(TestBookResource::class, new AgentTools(TestBookResource::class));

    $presented = \Murkrow\FilamentAi\Agent\Resources\RecordPresenter::present($model->newQuery()->first(), $blueprint, ['title', 'progress'], withUrl: false);

    expect($presented)->toHaveKey('title', 'Statuti')->not->toHaveKey('progress');
});
