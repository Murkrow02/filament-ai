<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

/**
 * Everything the agent tools need to know about one resource, resolved once.
 *
 * Built by `ResourceInspector` from the resource's own declarations and its
 * `agentTools()` overrides, so the tools never reach into Filament internals.
 */
final readonly class ResourceBlueprint
{
    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @param  list<string>  $abilities
     * @param  list<string>  $searchColumns  plain columns of the model's table
     * @param  list<string>  $listAttributes  returned by the list tool
     * @param  list<string>  $viewAttributes  returned by the view tool
     */
    public function __construct(
        public string $resource,
        public string $toolPrefix,
        public string $label,
        public string $pluralLabel,
        public array $abilities,
        public array $searchColumns,
        public array $listAttributes,
        public array $viewAttributes,
        public int $maxRecords,
        public ?string $description = null,
    ) {}

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }
}
