<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Filament\Panel;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Filament\Pages\IngestKnowledge;
use Murkrow\FilamentAi\Filament\Pages\KnowledgeDashboard;
use Murkrow\FilamentAi\Filament\Pages\KnowledgePlayground;
use Murkrow\FilamentAi\Filament\Pages\KnowledgeSettings;
use Murkrow\FilamentAi\Filament\FilamentAiPlugin;
use Murkrow\FilamentAi\Filament\Resources\DocumentResource;
use Murkrow\FilamentAi\Filament\Resources\IngestionRunResource;
use Murkrow\FilamentAi\Filament\Resources\QueryResource;
use Murkrow\FilamentAi\Filament\Widgets\KnowledgeStatsOverview;
use Murkrow\FilamentAi\Filament\Widgets\LatestRunsTable;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Settings\SettingsRepository;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedForPanel(): TestBook
{
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $book->pages()->create([
        'number' => 1,
        'content' => 'Il podesta convoco il consiglio generale. Le mura vennero rinforzate e le porte sbarrate al tramonto.',
    ]);
    $book->pages()->create([
        'number' => 2,
        'content' => 'Il grano venne razionato per tutto inverno. I mercanti protestarono davanti al palazzo comunale.',
    ]);

    FilamentAi::ingestSync('books');

    return $book;
}

it('registers its pages, resources and widgets with the host panel', function (): void {
    $panel = Filament::getPanel('testing');

    expect($panel->getPages())->toContain(KnowledgeDashboard::class, IngestKnowledge::class, KnowledgePlayground::class, KnowledgeSettings::class)
        ->and($panel->getResources())->toContain(IngestionRunResource::class, DocumentResource::class, QueryResource::class)
        ->and($panel->getWidgets())->toContain(KnowledgeStatsOverview::class);
});

it('registers nothing when the panel integration is switched off', function (): void {
    config()->set('filament-ai.filament.enabled', false);

    $panel = Panel::make()->id('disabled');
    FilamentAiPlugin::make()->register($panel);

    expect($panel->getPages())->toBeEmpty()
        ->and($panel->getResources())->toBeEmpty();
});

it('honours the per-page config switches', function (): void {
    config()->set('filament-ai.filament.pages.playground', false);
    config()->set('filament-ai.filament.resources.queries', false);

    $panel = Panel::make()->id('switched');
    FilamentAiPlugin::make()->register($panel);

    expect($panel->getPages())->not->toContain(KnowledgePlayground::class)
        ->and($panel->getResources())->not->toContain(QueryResource::class)
        ->and($panel->getPages())->toContain(KnowledgeDashboard::class);
});

it('puts everything under the configured navigation group and slug prefix', function (): void {
    config()->set('filament-ai.filament.navigation_group', 'Gestione');

    expect(KnowledgeDashboard::getNavigationGroup())->toBe('Gestione')
        ->and(IngestionRunResource::getNavigationGroup())->toBe('Gestione')
        ->and(KnowledgeDashboard::getSlug())->toStartWith('ai/')
        ->and(IngestionRunResource::getSlug())->toBe('ai/ingestion-runs');
});

it('renders the dashboard as static blade with no livewire polling', function (): void {
    seedForPanel();

    $html = Livewire::test(KnowledgeDashboard::class)
        ->assertOk()
        ->assertSee('Knowledge base')
        ->html();

    expect($html)->not->toContain('wire:poll');
});

it('renders the stats widget with real numbers', function (): void {
    seedForPanel();

    Livewire::test(KnowledgeStatsOverview::class)
        ->assertOk()
        ->assertSee('Coverage');
});

it('renders the ingestion form and estimates a run', function (): void {
    seedForPanel();

    Livewire::test(IngestKnowledge::class)
        ->assertOk()
        ->fillForm(['source' => 'books', 'mode' => 'incremental'])
        ->call('estimateAction')
        ->assertHasNoErrors()
        ->assertSee('Estimate');
});

it('starts a run from the ingestion form', function (): void {
    seedForPanel();

    Livewire::test(IngestKnowledge::class)
        ->fillForm([
            'source' => 'books',
            'mode' => 'full',
            'target_tokens' => 256,
            'overlap_tokens' => 32,
        ])
        ->call('start')
        ->assertHasNoErrors();

    $run = IngestionRun::query()->latest('id')->firstOrFail();

    expect($run->source_key)->toBe('books')
        ->and($run->chunking_params['target_tokens'])->toBe(256);
});

it('answers a question from the playground and shows the passages', function (): void {
    seedForPanel();

    Livewire::test(KnowledgePlayground::class)
        ->assertOk()
        ->fillForm(['question' => 'chi convoco il consiglio?', 'answer_mode' => true])
        ->call('run')
        ->assertHasNoErrors()
        ->assertSee('Retrieved passages');
});

it('hides the model dropdown when no models are configured', function (): void {
    seedForPanel();

    config()->set('filament-ai.llm.available_models', []);

    Livewire::test(KnowledgePlayground::class)
        ->assertOk()
        ->assertFormFieldIsHidden('model');
});

it('lets the playground override the model per query', function (): void {
    seedForPanel();

    config()->set('filament-ai.llm.available_models', [
        'fake-a' => 'Fake A',
        'fake-b' => 'Fake B',
    ]);

    $component = Livewire::test(KnowledgePlayground::class)
        ->assertOk()
        ->assertFormFieldIsVisible('model')
        ->fillForm(['question' => 'chi convoco il consiglio?', 'answer_mode' => true, 'model' => 'fake-b'])
        ->call('run')
        ->assertHasNoErrors();

    expect($component->get('diagnostics')['model'] ?? null)->toBe('fake-b');
});

it('retrieves without calling the model when answering is off', function (): void {
    seedForPanel();

    $component = Livewire::test(KnowledgePlayground::class)
        ->fillForm(['question' => 'mura e porte', 'answer_mode' => false])
        ->call('run')
        ->assertHasNoErrors();

    expect($component->get('answer'))->toBeNull()
        ->and($component->get('passages'))->not->toBeEmpty();
});

it('saves and reverts runtime settings', function (): void {
    config()->set('filament-ai.settings.enabled', true);

    Livewire::test(KnowledgeSettings::class)
        ->assertOk()
        ->fillForm(['retrieval__top_k' => 5])
        ->call('save')
        ->assertHasNoErrors();

    expect(app(SettingsRepository::class)->get('retrieval.top_k'))->toBe(5);

    Livewire::test(KnowledgeSettings::class)->call('resetToDefaults')->assertHasNoErrors();

    expect(app(SettingsRepository::class)->get('retrieval.top_k'))->toBeNull();
});

it('lists ingestion runs with their progress', function (): void {
    seedForPanel();
    $run = FilamentAi::ingestSync('books');

    Livewire::test(IngestionRunResource\Pages\ListIngestionRuns::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$run]);
});

it('shows a single run', function (): void {
    seedForPanel();
    $run = FilamentAi::ingestSync('books');

    Livewire::test(IngestionRunResource\Pages\ViewIngestionRun::class, ['record' => $run->uuid])
        ->assertOk()
        ->assertSee($run->uuid);
});

it('cancels a running run from the table', function (): void {
    seedForPanel();

    $run = IngestionRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'source_key' => 'books',
        'status' => RunStatus::Chunking,
        'mode' => IngestionMode::Full,
        'embedding_model' => 'fake-embedding',
        'embedding_dimensions' => 64,
        'vector_driver' => 'memory',
    ]);

    Livewire::test(IngestionRunResource\Pages\ListIngestionRuns::class)
        ->callAction(TestAction::make('cancel')->table($run))
        ->assertHasNoErrors();

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled);
});

it('browses indexed documents and their chunks', function (): void {
    seedForPanel();

    $document = Document::query()->firstOrFail();

    Livewire::test(DocumentResource\Pages\ListDocuments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$document]);

    Livewire::test(DocumentResource\Pages\ViewDocument::class, ['record' => $document->getKey()])
        ->assertOk()
        ->assertSee('Cronaca cittadina');
});

it('lists answered questions', function (): void {
    seedForPanel();

    FilamentAi::ask('chi convoco il consiglio?');

    Livewire::test(QueryResource\Pages\ListQueries::class)
        ->assertOk()
        ->assertSee('chi convoco il consiglio?');
});

it('does not poll an idle dashboard', function (): void {
    seedForPanel();

    // Several components refreshing at once race the host panel's
    // AuthenticateSession middleware into regenerating the session, which the
    // browser reports as "Page Expired". An idle dashboard has nothing to poll
    // for, so it must not.
    $html = Livewire::test(LatestRunsTable::class)->assertOk()->html();

    expect($html)->not->toContain('wire:poll');
});

it('polls the runs table while a run is in flight', function (): void {
    seedForPanel();

    IngestionRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'source_key' => 'books',
        'status' => RunStatus::Embedding,
        'mode' => IngestionMode::Full,
        'embedding_model' => 'fake-embedding',
        'embedding_dimensions' => 64,
        'vector_driver' => 'memory',
    ]);

    $html = Livewire::test(LatestRunsTable::class)->assertOk()->html();

    expect($html)->toContain('wire:poll');
});

it('never polls when the interval is disabled', function (): void {
    config()->set('filament-ai.filament.poll_interval', null);

    IngestionRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'source_key' => 'books',
        'status' => RunStatus::Embedding,
        'mode' => IngestionMode::Full,
        'embedding_model' => 'fake-embedding',
        'embedding_dimensions' => 64,
        'vector_driver' => 'memory',
    ]);

    expect(Livewire::test(LatestRunsTable::class)->assertOk()->html())->not->toContain('wire:poll');
});

it('renders the chunk-card component from the package view namespace', function (): void {
    // Regression: this resolved only because the test case used to put the
    // package's view directory on the application's view paths, which no real
    // host does. <x-filament-ai::chunk-card /> must resolve as filament-ai::components.chunk-card.
    expect(view()->exists('filament-ai::components.chunk-card'))->toBeTrue();

    $html = Blade::render('<x-filament-ai::chunk-card :passage="$p" />', ['p' => [
        'marker' => 1,
        'label' => 'A book - Page 7',
        'score' => 0.82,
        'content' => 'passage text',
        'url' => null,
        'used' => true,
    ]]);

    expect($html)->toContain('passage text')->toContain('[#1]');
});
