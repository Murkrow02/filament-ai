<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

/**
 * Default implementation of `AgentResource`: every derived tool, unchanged.
 *
 * Override `agentTools()` on the resource to narrow it:
 *
 *     public static function agentTools(AgentTools $tools): AgentTools
 *     {
 *         return $tools->only(AgentTools::LIST)->searchUsing(['number', 'customer_name']);
 *     }
 */
trait InteractsWithAgent
{
    public static function agentTools(AgentTools $tools): AgentTools
    {
        return $tools;
    }
}
