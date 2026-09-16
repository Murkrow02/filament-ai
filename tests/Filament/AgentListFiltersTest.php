<?php

declare(strict_types=1);

use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\FilterBlueprint;
use Murkrow\FilamentAi\Agent\Resources\ResourceInspector;
use Murkrow\FilamentAi\Agent\Resources\Tools\ListRecordsTool;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * Without the table's filters the list tool can only match text, so a question
 * like "how many are marked bad OCR" has no honest answer: the model searches
 * the word, finds nothing and reports zero. Found exactly that way in the demo
 * application, where "quante attivita aperte" answered 0 out of 14.
 */

function bookListTool(): ListRecordsTool
{
    return new ListRecordsTool(
        app(ResourceInspector::class)->inspect(TestBookResource::class, new AgentTools(TestBookResource::class)),
    );
}

/**
 * @return array<string, mixed>
 */
function listBooks(array $arguments = []): array
{
    return json_decode(bookListTool()->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    TestBook::create(['title' => 'Cronaca cittadina', 'author' => 'Anonimo', 'bad_ocr' => true]);
    TestBook::create(['title' => 'Statuti del comune', 'author' => 'Ser Piero', 'bad_ocr' => false]);
    TestBook::create(['title' => 'Registro delle gabelle', 'author' => 'Ser Piero', 'bad_ocr' => false]);
});

it('derives the table filters as tool arguments', function (): void {
    $filters = app(ResourceInspector::class)
        ->inspect(TestBookResource::class, new AgentTools(TestBookResource::class))
        ->filters;

    expect(array_map(fn (FilterBlueprint $f): string => $f->name, $filters))->toBe(['author', 'bad_ocr'])
        ->and($filters[0]->type)->toBe(FilterBlueprint::ENUM)
        ->and($filters[0]->options)->toBe(['Anonimo' => 'Anonimo', 'Ser Piero' => 'Ser Piero'])
        ->and($filters[1]->type)->toBe(FilterBlueprint::BOOLEAN);
});

it('offers the filters alongside search and pagination', function (): void {
    $arguments = array_keys(bookListTool()->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory));

    expect($arguments)->toBe(['page', 'per_page', 'search', 'author', 'bad_ocr']);
});

it('narrows by a select filter the way the table does', function (): void {
    $result = listBooks(['author' => 'Ser Piero']);

    expect($result['total'])->toBe(2)
        ->and(array_column($result['records'], 'author'))->toBe(['Ser Piero', 'Ser Piero']);
});

it('narrows by a ternary filter', function (): void {
    expect(listBooks(['bad_ocr' => true])['total'])->toBe(1)
        ->and(listBooks(['bad_ocr' => false])['total'])->toBe(2)
        ->and(listBooks()['total'])->toBe(3);
});

it('combines a filter with a search', function (): void {
    $result = listBooks(['author' => 'Ser Piero', 'search' => 'gabelle']);

    expect($result['total'])->toBe(1)
        ->and($result['records'][0]['title'])->toBe('Registro delle gabelle');
});

it('mentions the filters in its description', function (): void {
    expect(bookListTool()->description())->toContain('Narrow it with: author, bad_ocr');
});
