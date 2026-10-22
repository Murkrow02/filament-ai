<?php

declare(strict_types=1);

use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\FilterBlueprint;
use Murkrow\FilamentAi\Agent\Resources\FilterField;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Resources\ResourceInspector;
use Murkrow\FilamentAi\Agent\Resources\Tools\ListRecordsTool;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestCatalogueResource;
use Murkrow\FilamentAi\Tests\Fixtures\TestArticle;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * A shop's catalogue names a product in a related table, filters sales by a
 * date range and flags what is running out with a switch. Before these, the
 * list tool dropped all three: a product could not be found by its name, and
 * "sold this week" or "running out" had no argument at all.
 */

function catalogueBlueprint(): ResourceBlueprint
{
    return app(ResourceInspector::class)->inspect(TestCatalogueResource::class, new AgentTools(TestCatalogueResource::class));
}

/**
 * @return array<string, mixed>
 */
function listCatalogue(array $arguments = []): array
{
    $result = (new ListRecordsTool(catalogueBlueprint()))->handle(new Request($arguments));

    expect($result)->not->toStartWith('Error:');

    return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $linen = TestBook::create(['title' => 'Camicia lino coreana']);
    $denim = TestBook::create(['title' => 'Jeans blue']);

    TestArticle::create(['title' => 'Bianca M', 'status' => 'draft', 'test_book_id' => $linen->id, 'published_on' => '2026-09-03', 'code' => 'A1']);
    TestArticle::create(['title' => 'Bianca L', 'status' => 'draft', 'test_book_id' => $linen->id, 'published_on' => '2026-08-20', 'code' => 'A2']);
    TestArticle::create(['title' => 'Blue XL', 'status' => 'draft', 'test_book_id' => $denim->id, 'published_on' => null, 'code' => 'B1']);
});

it('searches a column reached through a relationship', function (): void {
    expect(catalogueBlueprint()->searchColumns)->toBe(['title', 'book.title']);

    $result = listCatalogue(['search' => 'lino coreana']);

    expect($result['total'])->toBe(2)
        ->and(array_column($result['records'], 'title'))->toEqualCanonicalizing(['Bianca M', 'Bianca L']);
});

it('drops a search column whose relationship or column does not exist', function (): void {
    $blueprint = app(ResourceInspector::class)->inspect(
        TestCatalogueResource::class,
        (new AgentTools(TestCatalogueResource::class))->searchUsing(['title', 'book.missing', 'nothing.title', 'book.title']),
    );

    expect($blueprint->searchColumns)->toBe(['title', 'book.title']);
});

it('maps a toggle filter, a date range form and a single text box', function (): void {
    $filters = collect(catalogueBlueprint()->filters)->keyBy('name');

    expect($filters->keys()->all())->toBe(['published', 'published_on', 'code', 'book'])
        ->and($filters['published']->type)->toBe(FilterBlueprint::BOOLEAN)
        ->and($filters['published']->toggle)->toBeTrue()
        ->and($filters['published_on']->type)->toBe(FilterBlueprint::FORM)
        ->and($filters['published_on']->arguments())->toBe(['published_on_from', 'published_on_until'])
        ->and($filters['published_on']->fields[0]->type)->toBe(FilterField::DATE)
        ->and($filters['published_on']->fields[0]->label)->toBe('Pubblicazione: Dal')
        ->and($filters['code']->arguments())->toBe(['code'])
        ->and($filters['code']->fields[0]->type)->toBe(FilterField::STRING);
});

it('never offers the trash filter', function (): void {
    expect(array_column(catalogueBlueprint()->filters, 'name'))->not->toContain('trashed');
});

it('lists a relationship select filter with its options', function (): void {
    $book = collect(catalogueBlueprint()->filters)->firstWhere('name', 'book');

    expect($book->type)->toBe(FilterBlueprint::ENUM)
        ->and(array_values($book->options))->toBe(['Camicia lino coreana', 'Jeans blue']);

    $linen = TestBook::query()->where('title', 'Camicia lino coreana')->value('id');

    expect(listCatalogue(['book' => (string) $linen])['total'])->toBe(2);
});

it('narrows by a toggle only when it is true', function (): void {
    expect(listCatalogue(['published' => true])['total'])->toBe(2)
        ->and(listCatalogue(['published' => false])['total'])->toBe(3);
});

it('narrows by a date range through the filter\'s own query', function (): void {
    expect(listCatalogue(['published_on_from' => '2026-09-01'])['total'])->toBe(1)
        ->and(listCatalogue(['published_on_from' => '2026-08-01', 'published_on_until' => '2026-08-31'])['records'][0]['title'])->toBe('Bianca L')
        ->and(listCatalogue(['code' => 'B1'])['records'][0]['title'])->toBe('Blue XL');
});

it('offers the form fields as typed arguments', function (): void {
    $schema = (new ListRecordsTool(catalogueBlueprint()))->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);

    expect(array_keys($schema))->toBe(['page', 'per_page', 'search', 'sort', 'direction', 'published', 'published_on_from', 'published_on_until', 'code', 'book'])
        ->and($schema['published_on_from']->toArray()['description'])->toBe('Pubblicazione: Dal (YYYY-MM-DD).')
        ->and($schema['sort']->toArray()['enum'])->toBe(['title', 'book.title', 'published_on']);
});

it('sorts by a column, through a relationship too', function (): void {
    expect(array_column(listCatalogue(['sort' => 'title'])['records'], 'title'))->toBe(['Bianca L', 'Bianca M', 'Blue XL'])
        ->and(array_column(listCatalogue(['sort' => 'published_on', 'direction' => 'desc', 'published' => true])['records'], 'title'))->toBe(['Bianca M', 'Bianca L'])
        ->and(array_column(listCatalogue(['sort' => 'book.title', 'direction' => 'desc'])['records'], 'title'))->toBe(['Blue XL', 'Bianca L', 'Bianca M']);
});

it('ignores a sort column the table does not offer', function (): void {
    expect(array_column(listCatalogue(['sort' => 'code'])['records'], 'title'))->toBe(['Blue XL', 'Bianca L', 'Bianca M']);
});
