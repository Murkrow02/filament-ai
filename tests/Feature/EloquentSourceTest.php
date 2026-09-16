<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Data\DocumentDraft;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function makeBook(string $title, array $pages, ?string $author = null): TestBook
{
    $book = TestBook::create(['title' => $title, 'author' => $author]);

    foreach ($pages as $index => $content) {
        $book->pages()->create(['number' => $index + 1, 'content' => $content]);
    }

    return $book;
}

it('resolves a source class listed in config', function (): void {
    $source = app(SourceRegistry::class)->get('books');

    expect($source->key())->toBe('books')
        ->and($source->label())->toBe('Books');
});

it('lists documents with their title and metadata', function (): void {
    makeBook('Storia di Firenze', ['Prima pagina.'], 'Anonimo');

    $drafts = app(SourceRegistry::class)->get('books')->documents()->all();

    expect($drafts)->toHaveCount(1);

    /** @var DocumentDraft $draft */
    $draft = $drafts[0];

    expect($draft->title)->toBe('Storia di Firenze')
        ->and($draft->metadata['author'])->toBe('Anonimo')
        ->and($draft->sourceKey)->toBe('books');
});

it('streams segments in position order', function (): void {
    $book = makeBook('Un libro', ['Pagina uno.', 'Pagina due.', 'Pagina tre.']);

    $segments = iterator_to_array(
        app(SourceRegistry::class)->get('books')->segments((string) $book->id),
    );

    expect($segments)->toHaveCount(3)
        ->and($segments[0]->position)->toBe(1)
        ->and($segments[2]->text)->toBe('Pagina tre.');
});

it('applies id, range and like filters from the declarative schema', function (): void {
    $first = makeBook('Alpha', ['a']);
    $second = makeBook('Beta', ['b']);
    makeBook('Gamma', ['c']);

    $source = app(SourceRegistry::class)->get('books');

    expect($source->countDocuments(['ids' => (string) $first->id]))->toBe(1)
        ->and($source->countDocuments(['id_range' => $first->id.'-'.$second->id]))->toBe(2)
        ->and($source->countDocuments(['title' => 'amm']))->toBe(1)
        ->and($source->countDocuments())->toBe(3);
});

it('renders singular and plural position labels', function (): void {
    $source = app(SourceRegistry::class)->get('books');

    expect($source->positionLabel(4, 4))->toBe('Page 4')
        ->and($source->positionLabel(4, 5))->toBe('Pages 4-5');
});

it('applies a boolean filter from its default, without being asked', function (): void {
    makeBook('Leggibile', ['a']);
    $bad = makeBook('Illeggibile', ['b']);
    $bad->update(['bad_ocr' => true]);

    $source = app(SourceRegistry::class)->get('books');

    expect($source->countDocuments())->toBe(1)
        ->and($source->countDocuments(['bad_ocr' => true]))->toBe(1)
        ->and($source->filterSet()->names())->toContain('bad_ocr');
});

it('rejects a configured entry that is not a knowledge source', function (): void {
    config()->set('rag.sources', [\stdClass::class]);
    app(SourceRegistry::class)->flush();

    expect(fn () => app(SourceRegistry::class)->keys())
        ->toThrow(\Murkrow\FilamentAi\Exceptions\InvalidSourceConfigurationException::class);
});

it('registers a closure-built source at runtime', function (): void {
    \Murkrow\FilamentAi\Facades\Rag::source('handbook')
        ->setLabel('Handbook')
        ->loadDocumentsUsing(fn (): \Illuminate\Support\LazyCollection => \Illuminate\Support\LazyCollection::make([
            new DocumentDraft(sourceKey: 'handbook', externalId: '1', title: 'Chapter one'),
        ]))
        ->loadSegmentsUsing(function (string $id): \Generator {
            yield new \Murkrow\FilamentAi\Data\Segment(1, 'Testo.');
        })
        ->register();

    $registry = app(SourceRegistry::class);

    expect($registry->keys())->toContain('handbook')
        ->and($registry->get('handbook')->countDocuments())->toBe(1);
});

it('reports an unknown source rather than failing silently', function (): void {
    expect(fn () => app(SourceRegistry::class)->get('nope'))
        ->toThrow(\Murkrow\FilamentAi\Exceptions\UnknownSourceException::class);
});
