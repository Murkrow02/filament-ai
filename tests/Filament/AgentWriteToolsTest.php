<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\ResourceInspector;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

class ReadOnlyTestBooksPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TestBook $book): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TestBook $book): bool
    {
        return false;
    }

    public function delete(User $user, TestBook $book): bool
    {
        return false;
    }
}

/**
 * @return array<string, Tool>
 */
function bookWriteTools(?AgentTools $tools = null): array
{
    $blueprint = app(ResourceInspector::class)->inspect(TestBookResource::class, $tools ?? new AgentTools(TestBookResource::class));

    $named = [];

    foreach (app(ResourceToolRegistry::class)->toolsFor($blueprint) as $tool) {
        $named[$tool->name()] = $tool;
    }

    return $named;
}

it('derives writable fields, their rules and their labels from the form', function (): void {
    $fields = app(ResourceInspector::class)->inspect(TestBookResource::class, new AgentTools(TestBookResource::class))->fields;

    expect(array_map(fn ($field) => $field->name, $fields))->toBe(['title', 'author'])
        ->and($fields[0]->required)->toBeTrue()
        ->and($fields[0]->label)->toBe('Title')
        ->and($fields[0]->rules)->toContain('required')->toContain('max:255')
        ->and($fields[1]->required)->toBeFalse();
});

it('describes create arguments from the form and edit arguments with the id', function (): void {
    $schema = new \Illuminate\JsonSchema\JsonSchemaTypeFactory;
    $tools = bookWriteTools();

    expect(array_keys($tools['test_books_create']->schema($schema)))->toBe(['title', 'author'])
        ->and(array_keys($tools['test_books_edit']->schema($schema)))->toBe(['id', 'title', 'author']);
});

it('asks the user to approve a creation, showing what will be saved', function (): void {
    $approval = bookWriteTools()['test_books_create']->shouldRequestApproval(new Request(['title' => 'Statuti del comune']));

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toBe('Create test book -- Title: Statuti del comune');
});

it('creates a record with the form fields only', function (): void {
    $output = bookWriteTools()['test_books_create']->handle(new Request([
        'title' => 'Statuti del comune',
        'author' => 'Ser Piero',
        // Not a form field: must not reach the model.
        'bad_ocr' => true,
    ]));

    $book = TestBook::query()->sole();

    expect(json_decode($output, true)['created'])->toBeTrue()
        ->and($book->title)->toBe('Statuti del comune')
        ->and((bool) $book->bad_ocr)->toBeFalse();
});

it('validates a creation with the form rules and saves nothing when invalid', function (): void {
    $output = bookWriteTools()['test_books_create']->handle(new Request(['author' => 'Ser Piero']));

    expect($output)->toStartWith('Error:')->toContain('Title')
        ->and(TestBook::query()->count())->toBe(0);
});

it('edits only the fields it is given', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina', 'author' => 'Anonimo']);

    bookWriteTools()['test_books_edit']->handle(new Request(['id' => (string) $book->id, 'author' => 'Giovanni Villani']));

    expect($book->refresh()->title)->toBe('Cronaca cittadina')
        ->and($book->author)->toBe('Giovanni Villani');
});

it('validates an edit and leaves the record untouched when invalid', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $output = bookWriteTools()['test_books_edit']->handle(new Request(['id' => (string) $book->id, 'title' => str_repeat('x', 300)]));

    expect($output)->toStartWith('Error:')
        ->and($book->refresh()->title)->toBe('Cronaca cittadina');
});

it('refuses writes the policies deny, even with a tool built before', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina']);
    $tools = bookWriteTools();

    Gate::policy(TestBook::class, ReadOnlyTestBooksPolicy::class);

    expect($tools['test_books_create']->handle(new Request(['title' => 'Nuovo'])))->toStartWith('Error: the current user is not allowed')
        ->and($tools['test_books_edit']->handle(new Request(['id' => (string) $book->id, 'title' => 'Nuovo'])))->toStartWith('Error: the current user is not allowed')
        ->and(TestBook::query()->pluck('title')->all())->toBe(['Cronaca cittadina']);
});

it('does not offer creation to a user who may not create', function (): void {
    Gate::policy(TestBook::class, ReadOnlyTestBooksPolicy::class);

    expect(bookWriteTools())->not->toHaveKey('test_books_create');
});

it('offers deletion only when the resource asks for it', function (): void {
    expect(bookWriteTools())->not->toHaveKey('test_books_delete')
        ->and(bookWriteTools((new AgentTools(TestBookResource::class))->with(AgentTools::DELETE)))->toHaveKey('test_books_delete');
});

it('deletes a record', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina']);
    $tool = bookWriteTools((new AgentTools(TestBookResource::class))->with(AgentTools::DELETE))['test_books_delete'];

    $tool->handle(new Request(['id' => (string) $book->id]));

    expect(TestBook::query()->count())->toBe(0);
});

it('always asks before deleting', function (): void {
    $tool = bookWriteTools((new AgentTools(TestBookResource::class))->with(AgentTools::DELETE)->withoutApproval(AgentTools::CREATE, AgentTools::EDIT))['test_books_delete'];

    expect($tool->shouldRequestApproval(new Request(['id' => '1'])))->toBeInstanceOf(Approval::class);
});

it('refuses to turn approval off for deletion', function (): void {
    (new AgentTools(TestBookResource::class))->withoutApproval(AgentTools::DELETE);
})->throws(InvalidArgumentException::class);

it('lets a resource skip approval for creation and edits', function (): void {
    $tools = bookWriteTools((new AgentTools(TestBookResource::class))->withoutApproval(AgentTools::CREATE, AgentTools::EDIT));

    expect($tools['test_books_create']->shouldRequestApproval(new Request(['title' => 'x'])))->toBeNull()
        ->and($tools['test_books_edit']->shouldRequestApproval(new Request(['id' => '1'])))->toBeNull();
});
