<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Resources\Resource;

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
     * @param  list<FieldBlueprint>  $fields  form fields offered when creating
     * @param  list<FieldBlueprint>|null  $editFields  form fields offered when editing; null means the same as $fields
     * @param  list<FilterBlueprint>  $filters  the table's filters, as list arguments
     * @param  list<string>  $unapprovedAbilities  writes that run without asking
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
        public array $fields = [],
        public array $unapprovedAbilities = [],
        public array $filters = [],
        public ?array $editFields = null,
    ) {}

    /**
     * @return list<FieldBlueprint>
     */
    public function fieldsFor(string $ability): array
    {
        return $ability === AgentTools::EDIT ? ($this->editFields ?? $this->fields) : $this->fields;
    }

    public function field(string $name): ?FieldBlueprint
    {
        foreach ([...$this->fields, ...($this->editFields ?? [])] as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    public function requiresApproval(string $ability): bool
    {
        return $ability === AgentTools::DELETE || ! in_array($ability, $this->unapprovedAbilities, true);
    }
}
