<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Livewire\Livewire;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Murkrow\FilamentAi\Filament\Pages\AgentSettings;
use Murkrow\FilamentAi\Filament\Pages\RagSettings;
use Murkrow\FilamentAi\Settings\SettingsRepository;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;

beforeEach(function (): void {
    config()->set('rag.settings.enabled', true);
});

/**
 * @return list<string>
 */
function toolNamesAfterSaving(SettingsRepository $settings): array
{
    $settings->apply();

    return array_map(fn ($tool): string => $tool->name(), app(ResourceToolRegistry::class)->tools());
}

it('is registered on the panel', function (): void {
    expect(Filament::getPanel('testing')->getPages())->toContain(AgentSettings::class);
});

it('shows every opted-in resource with what its class offers', function (): void {
    Livewire::test(AgentSettings::class)
        ->assertOk()
        ->assertSee('Test books')
        ->assertSee(TestBookResource::class)
        ->assertSee('List and search')
        // The fixture never offers deletion, so the page must not either:
        // it can take abilities away, never invent them.
        ->assertDontSee('Delete')
        ->assertSet('data.resources.0.abilities', AgentTools::DEFAULTS);
});

it('stores nothing while the form matches the code', function (): void {
    $settings = app(SettingsRepository::class);

    Livewire::test(AgentSettings::class)->call('save');

    expect($settings->get('agent.resources.overrides'))->toBe([])
        ->and(toolNamesAfterSaving($settings))->toContain('test_books_create');
});

it('takes an ability away from a resource', function (): void {
    $settings = app(SettingsRepository::class);

    Livewire::test(AgentSettings::class)
        ->set('data.resources.0.abilities', [AgentTools::LIST, AgentTools::VIEW])
        ->call('save')
        ->assertHasNoErrors();

    expect($settings->get('agent.resources.overrides'))
        ->toBe([TestBookResource::class => ['abilities' => [AgentTools::LIST, AgentTools::VIEW]]])
        ->and(toolNamesAfterSaving($settings))->toBe(['test_books_list', 'test_books_view']);
});

it('lets a write run without asking', function (): void {
    $settings = app(SettingsRepository::class);

    Livewire::test(AgentSettings::class)
        ->set('data.resources.0.unapproved', [AgentTools::EDIT])
        ->call('save');

    $settings->apply();

    $tools = [];

    foreach (app(ResourceToolRegistry::class)->tools() as $tool) {
        $tools[$tool->name()] = $tool;
    }

    expect($tools['test_books_edit']->shouldRequestApproval(new Laravel\Ai\Tools\Request(['id' => '1'])))->toBeNull()
        ->and($tools['test_books_create']->shouldRequestApproval(new Laravel\Ai\Tools\Request(['title' => 'x'])))->not->toBeNull();
});

it('changes the assistant model and the record cap', function (): void {
    $settings = app(SettingsRepository::class);

    Livewire::test(AgentSettings::class)
        ->set('data.agent__provider', 'anthropic')
        ->set('data.agent__model', 'claude-sonnet-5')
        ->set('data.agent__resources__max_records', 5)
        ->call('save');

    $settings->apply();

    expect(config('rag.agent.provider'))->toBe('anthropic')
        ->and(config('rag.agent.model'))->toBe('claude-sonnet-5')
        ->and(app(ResourceToolRegistry::class)->blueprints()[0]->maxRecords)->toBe(5);
});

it('reverts to what the code says', function (): void {
    $settings = app(SettingsRepository::class);

    Livewire::test(AgentSettings::class)
        ->set('data.resources.0.abilities', [AgentTools::LIST])
        ->call('save')
        ->call('resetToDefaults');

    expect($settings->get('agent.resources.overrides'))->toBeNull()
        ->and(toolNamesAfterSaving($settings))->toContain('test_books_create');
});

it('is closed to whoever may not administer the knowledge panel', function (): void {
    config()->set('rag.filament.authorize', fn (): bool => false);

    Livewire::test(AgentSettings::class)->assertForbidden();
});

it('keeps the agent keys out of the knowledge settings form', function (): void {
    $html = Livewire::test(RagSettings::class)->assertOk()->html();

    expect($html)->not->toContain('agent__resources__overrides')
        ->and($html)->toContain('retrieval__top_k');
});
