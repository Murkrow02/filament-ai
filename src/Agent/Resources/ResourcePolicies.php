<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Resources\Resource;

/**
 * The policies an administrator set for a resource, on top of what the
 * resource itself declared in `agentTools()`.
 *
 * They live in `filament-ai.agent.resources.overrides`, which the settings repository
 * writes from the panel, so what the agent may do can be changed without a
 * deployment. The settings can only take away: a resource that never opted
 * in cannot be enabled from here, an ability the code did not offer cannot be
 * added, and a write the code asks the user about cannot be made silent.
 */
final class ResourcePolicies
{
    /**
     * @return array<class-string<\Filament\Resources\Resource>, array<string, mixed>>
     */
    public static function all(): array
    {
        $overrides = config('filament-ai.agent.resources.overrides', []);

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return array<string, mixed>
     */
    public static function for(string $resource): array
    {
        $override = self::all()[$resource] ?? [];

        return is_array($override) ? $override : [];
    }

    /**
     * Narrow what the resource declared. Abilities are intersected, never
     * widened: an administrator can take away what the code offered, and a
     * resource that offered nothing stays silent.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function apply(AgentTools $tools, string $resource): AgentTools
    {
        $override = self::for($resource);

        if ($override === []) {
            return $tools;
        }

        if (isset($override['abilities']) && is_array($override['abilities'])) {
            $allowed = array_values(array_intersect($tools->abilities(), self::strings($override['abilities'])));

            $tools = $allowed === [] ? $tools->only() : $tools->only(...$allowed);
        }

        if (isset($override['unapproved']) && is_array($override['unapproved'])) {
            // The administrator can put a question back, never take one away
            // the code asks: text inside a record could otherwise steer an
            // unsupervised write. Only what the resource itself runs without
            // approval can stay that way, and an empty list asks for all.
            $keep = array_intersect($tools->unapprovedAbilities(), self::strings($override['unapproved']));
            $tools = $tools->requireApproval(...array_diff($tools->unapprovedAbilities(), $keep));
        }

        if (isset($override['max_records']) && is_numeric($override['max_records'])) {
            $tools = $tools->limit((int) $override['max_records']);
        }

        if (isset($override['description']) && is_string($override['description']) && trim($override['description']) !== '') {
            $tools = $tools->describe(trim($override['description']));
        }

        return $tools;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_map(strval(...), array_filter($values, static fn (mixed $value): bool => is_scalar($value))));
    }
}
