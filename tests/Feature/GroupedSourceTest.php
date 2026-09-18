<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Data\DocumentDraft;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;
use Murkrow\FilamentAi\Tests\Fixtures\TestTitleIndexSource;

beforeEach(function (): void {
    config()->set('filament-ai.sources', [TestTitleIndexSource::class]);
    app(SourceRegistry::class)->flush();

    foreach (['Amalfi', 'Atrani', 'Amalfi Costiera', 'Benevento', 'Capri'] as $title) {
        TestBook::create(['title' => $title]);
    }
});

it('turns each distinct group into one document', function (): void {
    $drafts = app(SourceRegistry::class)->get('titles')->documents()->all();

    expect($drafts)->toHaveCount(3);

    /** @var DocumentDraft $first */
    $first = $drafts[0];

    expect($first->externalId)->toBe('A')
        ->and($first->title)->toBe('Titles - A')
        ->and($first->metadata['entries'])->toBe(3);
});

it('counts documents, not rows', function (): void {
    $source = app(SourceRegistry::class)->get('titles');

    expect($source->countDocuments())->toBe(3)
        ->and($source->countDocuments(['title' => 'mal']))->toBe(1);
});

it('streams a group as ordered segments numbered from one', function (): void {
    $segments = iterator_to_array(app(SourceRegistry::class)->get('titles')->segments('A'));

    expect($segments)->toHaveCount(3)
        ->and($segments[0]->position)->toBe(1)
        ->and($segments[0]->text)->toBe('Amalfi')
        ->and($segments[2]->position)->toBe(3)
        ->and($segments[2]->text)->toBe('Atrani');
});

it('finds one document by its group value and nothing by an absent one', function (): void {
    $source = app(SourceRegistry::class)->get('titles');

    expect($source->findDocument('B')?->title)->toBe('Titles - B')
        ->and($source->findDocument('Z'))->toBeNull();
});

it('ingests every group into chunks that pack several rows', function (): void {
    FilamentAi::ingestSync('titles', mode: IngestionMode::Full);

    expect(Document::query()->where('source_key', 'titles')->count())->toBe(3);

    $document = Document::query()->where('external_id', 'A')->firstOrFail();
    $chunk = Chunk::query()->where('document_id', $document->id)->firstOrFail();

    expect($chunk->content)->toContain('Amalfi')
        ->and($chunk->content)->toContain('Atrani')
        ->and($chunk->position_start)->toBe(1)
        ->and($chunk->position_end)->toBe(3);
});

it('renders positions as entries', function (): void {
    $source = app(SourceRegistry::class)->get('titles');

    expect($source->positionLabel(1, 3))->toBe('Entries 1-3')
        ->and($source->positionLabel(2, 2))->toBe('Entry 2');
});
