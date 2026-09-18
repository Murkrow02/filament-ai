<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\ResourcePolicies;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Murkrow\FilamentAi\Settings\SettingsRepository;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;

/*
 * What an administrator may change about a resource at runtime, and what only
 * the code decides.
 */

/**
 * @return list<string>
 */
function agentToolNames(): array
{
    return array_map(fn ($tool): string => $tool->name(), app(ResourceToolRegistry::class)->tools());
}

it('takes abilities away from a resource', function (): void {
    config()->set('filament-ai.agent.resources.overrides', [
        TestBookResource::class => ['abilities' => [AgentTools::LIST]],
    ]);

    expect(agentToolNames())->toBe(['test_books_list']);
});

it('never widens what the resource declared', function (): void {
    // The resource itself does not offer deletion, so asking for it here
    // changes nothing: the code, not the panel, decides what is offerable.
    config()->set('filament-ai.agent.resources.overrides', [
        TestBookResource::class => ['abilities' => AgentTools::ABILITIES],
    ]);

    expect(agentToolNames())->not->toContain('test_books_delete');
});

it('can silence a resource entirely', function (): void {
    config()->set('filament-ai.agent.resources.overrides', [
        TestBookResource::class => ['abilities' => []],
    ]);

    expect(agentToolNames())->toBe([]);
});

it('can let a write run without asking the user', function (): void {
    config()->set('filament-ai.agent.resources.overrides', [
        TestBookResource::class => ['unapproved' => [AgentTools::CREATE]],
    ]);

    $tools = [];

    foreach (app(ResourceToolRegistry::class)->tools() as $tool) {
        $tools[$tool->name()] = $tool;
    }

    expect($tools['test_books_create']->shouldRequestApproval(new Laravel\Ai\Tools\Request(['title' => 'x'])))->toBeNull()
        ->and($tools['test_books_edit']->shouldRequestApproval(new Laravel\Ai\Tools\Request(['id' => '1'])))->not->toBeNull();
});

it('ignores an attempt to take approval off a deletion', function (): void {
    config()->set('filament-ai.agent.resources.overrides', [
        TestBookResource::class => ['unapproved' => [AgentTools::DELETE]],
    ]);

    $tools = ResourcePolicies::apply(
        (new AgentTools(TestBookResource::class))->with(AgentTools::DELETE),
        TestBookResource::class,
    );

    expect($tools->unapprovedAbilities())->not->toContain(AgentTools::DELETE);
});

it('caps records per resource', function (): void {
    config()->set('filament-ai.agent.resources.overrides', [
        TestBookResource::class => ['max_records' => 3],
    ]);

    $blueprint = app(ResourceToolRegistry::class)->blueprints()[0];

    expect($blueprint->maxRecords)->toBe(3);
});

it('lists every opted-in resource, including the ones it switched off', function (): void {
    config()->set('filament-ai.agent.resources.overrides', [
        TestBookResource::class => ['abilities' => []],
    ]);

    $catalogue = app(ResourceToolRegistry::class)->catalogue();

    expect(array_column($catalogue, 'resource'))->toBe([TestBookResource::class])
        ->and($catalogue[0]['abilities'])->toBe(AgentTools::DEFAULTS)
        ->and($catalogue[0]['plural'])->toBe('test books');
});

it('stores the policies through the settings repository', function (): void {
    config()->set('filament-ai.settings.enabled', true);

    $settings = app(SettingsRepository::class);
    $settings->set('agent.resources.overrides', [
        TestBookResource::class => ['abilities' => [AgentTools::LIST, AgentTools::VIEW]],
    ]);
    $settings->apply();

    expect(agentToolNames())->toBe(['test_books_list', 'test_books_view'])
        ->and($settings->get('agent.resources.overrides'))->toHaveKey(TestBookResource::class);
});
