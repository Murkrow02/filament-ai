<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Facades\Filament;
use Filament\Panel;
use Laravel\Ai\Contracts\Tool;
use Murkrow\FilamentAi\Agent\Resources\Tools\ListRecordsTool;
use Murkrow\FilamentAi\Agent\Resources\Tools\ViewRecordTool;

/**
 * The resource tools the current user's agent may call in one panel.
 *
 * Only resources implementing `AgentResource` are considered. A resource whose
 * `viewAny` policy denies the current user is left out entirely, so the model
 * is not told about records it cannot read -- but the tools still check the
 * policy on every call, because this list is built once per prompt and a
 * conversation can outlive a permission change.
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

            $tools = $resource::agentTools(
                (new AgentTools($resource))->limit((int) config('rag.agent.resources.max_records', 25)),
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
     * @return list<Tool>
     */
    public function tools(?Panel $panel = null): array
    {
        $tools = [];

        foreach ($this->blueprints($panel) as $blueprint) {
            if ($blueprint->allows(AgentTools::LIST)) {
                $tools[] = new ListRecordsTool($blueprint);
            }

            if ($blueprint->allows(AgentTools::VIEW)) {
                $tools[] = new ViewRecordTool($blueprint);
            }
        }

        return $tools;
    }
}
