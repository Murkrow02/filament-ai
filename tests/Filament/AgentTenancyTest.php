<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Chat\PanelScope;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Murkrow\FilamentAi\Http\Middleware\BootAssistantPanel;
use Murkrow\FilamentAi\Tests\Fixtures\TestTask;
use Murkrow\FilamentAi\Tests\Fixtures\TestTeam;
use Murkrow\FilamentAi\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    $this->mine = TestTeam::query()->create(['name' => 'Mine', 'slug' => 'mine']);
    $this->theirs = TestTeam::query()->create(['name' => 'Theirs', 'slug' => 'theirs']);

    TestTask::query()->create(['title' => 'My task', 'test_team_id' => $this->mine->id]);
    TestTask::query()->create(['title' => 'Their task', 'test_team_id' => $this->theirs->id]);

    TestUser::$teams = [$this->mine->id];
});

/**
 * @return array<string, Tool>
 */
function tenantTools(): array
{
    $tools = [];

    foreach (app(ResourceToolRegistry::class)->tools(Filament::getPanel('tenants')) as $tool) {
        $tools[$tool->name()] = $tool;
    }

    return $tools;
}

function enterTenantPanel(?string $tenant): void
{
    (new BootAssistantPanel)->handle(
        HttpRequest::create('/ai/chat/ask', 'POST', array_filter(['panel' => 'tenants', 'tenant' => $tenant]))->setUserResolver(fn () => auth()->user()),
        fn () => response('ok'),
    );
}

it('reads only the current tenant\'s records', function (): void {
    enterTenantPanel('mine');

    $output = json_decode(tenantTools()['test_tasks_list']->handle(new Request([])), true);

    expect($output['total'])->toBe(1)
        ->and($output['records'][0]['title'])->toBe('My task');
});

it('does not find another tenant\'s record by id', function (): void {
    enterTenantPanel('mine');

    $theirs = TestTask::query()->withoutGlobalScopes()->where('title', 'Their task')->value('id');

    expect(tenantTools()['test_tasks_view']->handle(new Request(['id' => (string) $theirs])))->toStartWith('Error: no test task');
});

it('gives a created record to the current tenant', function (): void {
    enterTenantPanel('mine');

    tenantTools()['test_tasks_create']->handle(new Request(['title' => 'New one']));

    expect(TestTask::query()->withoutGlobalScopes()->where('title', 'New one')->value('test_team_id'))->toBe($this->mine->id);
});

it('refuses a tenant the user does not belong to', function (): void {
    enterTenantPanel('theirs');

    // Not quietly swapped for another tenant either: the page asked for this
    // one, and answering about a different team would be as wrong.
    expect(Filament::getTenant())->toBeNull()
        ->and(tenantTools())->toBe([]);
});

it('acts in the user\'s default tenant when the page names none', function (): void {
    enterTenantPanel(null);

    expect(Filament::getTenant()?->is($this->mine))->toBeTrue();
});

it('offers no resource tools on a tenant panel without a tenant', function (): void {
    TestUser::$teams = [];

    enterTenantPanel(null);

    expect(Filament::getTenant())->toBeNull()
        ->and(tenantTools())->toBe([]);
});

it('offers no resource tools to a user who cannot open the panel', function (): void {
    TestUser::$panelAccess = false;

    PanelScope::enter(Filament::getPanel('testing'), auth()->user());

    expect(app(ResourceToolRegistry::class)->tools())->toBe([]);
});
