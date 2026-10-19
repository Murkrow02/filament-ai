<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Illuminate\Database\Eloquent\Builder;

/**
 * The query every agent read and write of a resource starts from.
 *
 * `Resource::getEloquentQuery()` -- tenant scoping and whatever the host
 * narrowed for its table -- then the resource's optional
 *
 *     public static function agentQuery(Builder $query): Builder
 *
 * for what the panel's people may see but the agent must not: soft-deleted
 * rows a table shows on purpose, records kept out of a language model's
 * context. Not part of the `AgentResource` interface, so resources written
 * before it keep working.
 */
final class AgentQuery
{
    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function for(string $resource): Builder
    {
        $query = $resource::getEloquentQuery();

        return method_exists($resource, 'agentQuery') ? $resource::agentQuery($query) : $query;
    }
}
