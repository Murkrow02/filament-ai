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
 *
 * The agent writes through the resource's form, but never mounts its pages,
 * so a page's `mutateFormDataBeforeCreate()` / `mutateFormDataBeforeSave()`
 * does not run. What those do that must also happen for the agent -- setting
 * the owner, a slug, a status -- goes in two optional static methods:
 *
 *     public static function agentMutateBeforeCreate(array $data): array
 *     {
 *         return [...$data, 'user_id' => auth()->id()];
 *     }
 *
 *     public static function agentMutateBeforeSave(Model $record, array $data): array
 */
trait InteractsWithAgent
{
    public static function agentTools(AgentTools $tools): AgentTools
    {
        return $tools;
    }
}
