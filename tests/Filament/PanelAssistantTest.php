<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

it('works without a subclass: knowledge plus every opted-in resource', function (): void {
    $names = array_map(fn ($tool): string => $tool->name(), [...(new PanelAssistant)->tools()]);

    expect($names)->toBe(['search_knowledge', 'fetch_document', 'test_books_list', 'test_books_view', 'test_books_create', 'test_books_edit']);
});

it('drops the knowledge tools when no source is allowed', function (): void {
    config()->set('filament-ai.agent.knowledge.sources', []);

    $names = array_map(fn ($tool): string => $tool->name(), [...(new PanelAssistant)->tools()]);

    expect($names)->toBe(['test_books_list', 'test_books_view', 'test_books_create', 'test_books_edit']);
});

it('narrows down to the tools a solving phase asked for', function (): void {
    $assistant = (new PanelAssistant)->onlyTools(['search_knowledge', 'test_books_list', 'no_such_tool']);

    $names = array_map(fn ($tool): string => $tool->name(), [...$assistant->tools()]);

    // Narrowing only: a name the assistant does not have does not add a tool.
    expect($names)->toBe(['search_knowledge', 'test_books_list'])
        ->and(array_map(fn ($tool): string => $tool->name(), [...$assistant->onlyTools(null)->tools()]))
        ->toContain('test_books_create');
});

it('lets a phase cap the tool round trips of one turn', function (): void {
    config()->set('filament-ai.agent.max_steps', 12);

    expect((new PanelAssistant)->maxSteps())->toBe(12)
        ->and((new PanelAssistant)->withMaxSteps(3)->maxSteps())->toBe(3)
        ->and((new PanelAssistant)->withMaxSteps(3)->withMaxSteps(null)->maxSteps())->toBe(12);
});

it('tells the model who is asking, where, and what it can read', function (): void {
    $instructions = (new PanelAssistant)->instructions();

    expect($instructions)
        ->toContain('Signed-in user: Panel user.')
        ->toContain('test_books_list / test_books_view / test_books_create / test_books_edit: test books')
        ->toContain('The user confirms each change in the interface before it runs');
});

it('describes the record on screen so "this" resolves', function (): void {
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $instructions = (new PanelAssistant)->onPage(TestBookResource::class, $book)->instructions();

    expect($instructions)->toContain("looking at the test book \"Cronaca cittadina\" (id {$book->id})");
});

it('lets a host give the assistant a voice and a domain', function (): void {
    $assistant = new class extends PanelAssistant
    {
        protected function persona(): string
        {
            return 'You are Ask Archive.';
        }

        protected function domain(): ?string
        {
            return 'Books are medieval chronicles.';
        }
    };

    expect($assistant->instructions())->toStartWith("You are Ask Archive.\n\nBooks are medieval chronicles.");
});

it('can be prompted end to end through laravel/ai', function (): void {
    PanelAssistant::fake(['There are no books yet.']);

    $response = (new PanelAssistant)->prompt('How many books are there?');

    expect($response->text)->toBe('There are no books yet.');

    PanelAssistant::assertPrompted('How many books are there?');
});

it('never tells the model it can change records when it cannot', function (): void {
    config()->set('filament-ai.agent.resources.writes', false);

    $assistant = new \Murkrow\FilamentAi\Agent\PanelAssistant;
    $names = array_map(fn ($tool) => $tool->name(), iterator_to_array($assistant->tools(), false));

    expect($names)->toContain('test_books_list')
        ->not->toContain('test_books_create', 'test_books_edit')
        ->and($assistant->instructions())
        ->toContain('you cannot create, change or delete anything')
        ->not->toContain('_create, _edit and _delete');
});

it('says it has no access to records when no resource is offered', function (): void {
    config()->set('filament-ai.agent.resources.enabled', false);

    expect((new \Murkrow\FilamentAi\Agent\PanelAssistant)->instructions())
        ->toContain('You have no access to the panel\'s records')
        ->not->toContain('_create, _edit and _delete');
});

it('keeps the write rule when writes are offered', function (): void {
    expect((new \Murkrow\FilamentAi\Agent\PanelAssistant)->instructions())->toContain('_create, _edit and _delete');
});
