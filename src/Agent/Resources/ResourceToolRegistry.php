<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Facades\Filament;
use Filament\Panel;
use Laravel\Ai\Contracts\Tool;
use Murkrow\FilamentAi\Agent\Resources\Tools\CreateRecordTool;
use Murkrow\FilamentAi\Agent\Resources\Tools\DeleteRecordTool;
use Murkrow\FilamentAi\Agent\Resources\Tools\EditRecordTool;
use Murkrow\FilamentAi\Agent\Resources\Tools\ListRecordsTool;
use Murkrow\FilamentAi\Agent\Resources\Tools\ViewRecordTool;

/**
 * The resource tools the current user's agent may call in one panel.
 *
 * Only resources implementing `AgentResource` are considered. A resource whose
 * `viewAny` policy denies the current user is left out entirely, and a create
 * tool is left out when `create` is denied, so the model is not told about
 * what it cannot do -- but every tool still checks its policy when called,
 * because this list is built once per prompt and a conversation can outlive a
 * permission change.
 */
final class ResourceToolRegistry
{
    public function __construct(private readonly ResourceInspector $inspector) {}

    /**
     * @return list<ResourceBlueprint>
     */
    public function blueprints(?Panel $panel = null): array
    {
        if (! config('rag.agent.resources.enabled', true)) {
            return [];
        }

        $panel ??= Filament::getCurrentOrDefaultPanel();

        if ($panel === null) {
            return [];
        }

        $blueprints = [];
        $prefixes = [];

        foreach ($panel->getResources() as $resource) {
            if (! is_subclass_of($resource, AgentResource::class) || ! $resource::canViewAny()) {
                continue;
            }

            $tools = ResourcePolicies::apply(
                $resource::agentTools(
                    (new AgentTools($resource))->limit((int) config('rag.agent.resources.max_records', 25)),
                ),
                $resource,
            );

            if ($tools->abilities() === []) {
                continue;
            }

            $blueprint = $this->inspector->inspect($resource, $tools);

            // Two resources over the same slug (different clusters, say) would
            // otherwise register two tools under one name, and the provider
            // would silently call whichever it saw last.
            if (isset($prefixes[$blueprint->toolPrefix])) {
                continue;
            }

            $prefixes[$blueprint->toolPrefix] = true;
            $blueprints[] = $blueprint;
        }

        return $blueprints;
    }

    /**
     * Every resource that opted in, as it was declared: before the
     * administrator's overrides and without the policy check.
     *
     * The settings page needs this, not `blueprints()`: a resource switched
     * off from the panel has to stay on the page, or there would be no way to
     * switch it back on.
     *
     * @return list<array{resource: class-string<\Filament\Resources\Resource>, label: string, plural: string, abilities: list<string>, unapproved: list<string>, max_records: int}>
     */
    public function catalogue(?Panel $panel = null): array
    {
        $panel ??= Filament::getCurrentOrDefaultPanel();

        if ($panel === null) {
            return [];
        }

        $declared = [];

        foreach ($panel->getResources() as $resource) {
            if (! is_subclass_of($resource, AgentResource::class)) {
                continue;
            }

            $tools = $resource::agentTools(
                (new AgentTools($resource))->limit((int) config('rag.agent.resources.max_records', 25)),
            );

            $declared[] = [
                'resource' => $resource,
                'label' => (string) $resource::getModelLabel(),
                'plural' => (string) $resource::getPluralModelLabel(),
                'abilities' => $tools->abilities(),
                'unapproved' => $tools->unapprovedAbilities(),
                'max_records' => $tools->maxRecords(),
            ];
        }

        return $declared;
    }

    /**
     * @return list<Tool>
     */
    public function tools(?Panel $panel = null): array
    {
        $tools = [];

        foreach ($this->blueprints($panel) as $blueprint) {
            array_push($tools, ...$this->toolsFor($blueprint));
        }

        return $tools;
    }

    /**
     * @return list<Tool>
     */
    public function toolsFor(ResourceBlueprint $blueprint): array
    {
        $resource = $blueprint->resource;
        $tools = [];

        if ($blueprint->allows(AgentTools::LIST)) {
            $tools[] = new ListRecordsTool($blueprint);
        }

        if ($blueprint->allows(AgentTools::VIEW)) {
            $tools[] = new ViewRecordTool($blueprint);
        }

        // Writes need something to write: a resource without a usable form
        // gets no create or edit tool rather than one that accepts nothing.
        if ($blueprint->allows(AgentTools::CREATE) && $blueprint->fields !== [] && $resource::canCreate()) {
            $tools[] = new CreateRecordTool($blueprint);
        }

        if ($blueprint->allows(AgentTools::EDIT) && $blueprint->fields !== []) {
            $tools[] = new EditRecordTool($blueprint);
        }

        if ($blueprint->allows(AgentTools::DELETE)) {
            $tools[] = new DeleteRecordTool($blueprint);
        }

        return $tools;
    }
}
