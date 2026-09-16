<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

/**
 * Marks a Filament resource as reachable by the panel agent.
 *
 * Exposure is opt-in per resource: a resource without this interface never
 * produces a tool, however it is configured. Use `InteractsWithAgent` for the
 * default, which derives read tools from what the resource already declares
 * (its query, table, form, record title and policies).
 */
interface AgentResource
{
    /**
     * Adjust what the agent may do with this resource.
     */
    public static function agentTools(AgentTools $tools): AgentTools;
}
