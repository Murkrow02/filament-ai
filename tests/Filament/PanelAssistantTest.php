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
    config()->set('rag.agent.knowledge.sources', []);

    $names = array_map(fn ($tool): string => $tool->name(), [...(new PanelAssistant)->tools()]);

    expect($names)->toBe(['test_books_list', 'test_books_view', 'test_books_create', 'test_books_edit']);
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
