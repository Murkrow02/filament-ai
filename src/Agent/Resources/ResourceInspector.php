<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads what a Filament resource already declares and turns it into a
 * `ResourceBlueprint` for the agent tools.
 *
 * A resource's table and form are configured against a Livewire component, and
 * none is mounted when the agent runs. Both are therefore built against an
 * unmounted instance of one of the resource's own pages. Only static facts
 * are read -- names, searchability, rules -- never state. Anything that
 * cannot be read degrades to "not derived" rather
 * than failing: a resource with an exotic table still gets a working tool, just
 * a less informed one.
 */
final class ResourceInspector
{
    /** @var array<string, list<string>> */
    private array $columnListings = [];

    public function __construct(
        private readonly FormFieldMapper $fields,
        private readonly TableFilterMapper $tableFilters,
        private readonly FormPipeline $forms,
    ) {}

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public function inspect(string $resource, AgentTools $tools): ResourceBlueprint
    {
        /** @var Model $model */
        $model = app($resource::getModel());
        $table = $this->table($resource);
        $columns = $table === null ? [] : $this->tableColumns($table);
        $tableNames = array_values(array_map(static fn (Column $column): string => $column->getName(), $columns));
        $createFields = $this->formFields($resource, FormPipeline::CREATE);
        $editFields = $this->formFields($resource, FormPipeline::EDIT);
        $formNames = array_values(array_unique(array_map(static fn (FieldBlueprint $field): string => $field->name, $editFields)));

        $searchColumns = $tools->searchColumns()
            ?? $this->searchableColumns($columns, $model)
            ?: $resource::getGloballySearchableAttributes();

        $configured = $tools->configuredAttributes();

        return new ResourceBlueprint(
            resource: $resource,
            toolPrefix: $this->toolPrefix($resource),
            label: (string) $resource::getModelLabel(),
            pluralLabel: (string) $resource::getPluralModelLabel(),
            abilities: $tools->abilities(),
            // Only real columns of the model's own table: a searchable column
            // backed by a relationship or a custom query callback would reach
            // SQL as a column that does not exist.
            searchColumns: array_values(array_intersect(array_unique($searchColumns), $this->columnListing($model))),
            listAttributes: $configured ?? $tableNames,
            viewAttributes: $configured ?? array_values(array_unique([...$tableNames, ...$formNames])),
            maxRecords: $tools->maxRecords(),
            description: $tools->description(),
            fields: $createFields,
            unapprovedAbilities: $tools->unapprovedAbilities(),
            filters: $table === null ? [] : $this->tableFilters($table),
            editFields: $editFields,
        );
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private function table(string $resource): ?Table
    {
        $livewire = $this->tablePage($resource);

        if ($livewire === null) {
            return null;
        }

        try {
            return $resource::table(Table::make($livewire));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return list<Column>
     */
    private function tableColumns(Table $table): array
    {
        return array_values(array_filter(
            $table->getColumns(),
            static fn (mixed $column): bool => $column instanceof Column,
        ));
    }

    /**
     * @return list<FilterBlueprint>
     */
    private function tableFilters(Table $table): array
    {
        try {
            $filters = $table->getFilters();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $blueprints = [];

        foreach ($filters as $filter) {
            if (! $filter instanceof BaseFilter) {
                continue;
            }

            $blueprint = $this->tableFilters->map($filter);

            if ($blueprint !== null) {
                $blueprints[] = $blueprint;
            }
        }

        return $blueprints;
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private function tablePage(string $resource): ?HasTable
    {
        $page = $this->pageImplementing($resource, HasTable::class);

        return $page instanceof HasTable ? $page : null;
    }

    /**
     * An unmounted instance of the first page of the resource that implements
     * the given contract, or null.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @param  class-string  $contract
     */
    private function pageImplementing(string $resource, string $contract): ?object
    {
        foreach ($resource::getPages() as $registration) {
            $page = $registration instanceof PageRegistration ? $registration->getPage() : null;

            if ($page !== null && is_subclass_of($page, $contract)) {
                try {
                    return app($page);
                } catch (Throwable $exception) {
                    report($exception);

                    return null;
                }
            }
        }

        return null;
    }

    /**
     * The form fields offered for an operation, built the way the create or
     * edit page builds its form: hidden, disabled and password fields for
     * that operation are not offered at all.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return list<FieldBlueprint>
     */
    private function formFields(string $resource, string $operation): array
    {
        if ($resource::getParentResourceRegistration() !== null) {
            // A nested resource's records are created through their parent's
            // relationship, which the agent has no parent record for.
            return [];
        }

        return array_values(array_filter(array_map($this->fields->map(...), $this->forms->offeredFields($resource, $operation))));
    }

    /**
     * @param  list<Column>  $columns
     * @return list<string>
     */
    private function searchableColumns(array $columns, Model $model): array
    {
        $searchable = [];

        foreach ($columns as $column) {
            try {
                if (! $column->isSearchable()) {
                    continue;
                }

                array_push($searchable, ...$column->getSearchColumns($model));
            } catch (Throwable) {
                continue;
            }
        }

        return array_values(array_unique($searchable));
    }

    /**
     * @return list<string>
     */
    private function columnListing(Model $model): array
    {
        $key = $model->getConnectionName().'|'.$model->getTable();

        return $this->columnListings[$key] ??= $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private function toolPrefix(string $resource): string
    {
        try {
            $slug = $resource::getSlug();
        } catch (Throwable) {
            $slug = Str::of(class_basename($resource))->beforeLast('Resource')->kebab()->plural()->toString();
        }

        // Provider tool names allow [A-Za-z0-9_-] and at most 64 characters;
        // the longest suffix appended is "_delete".
        return Str::of($slug)->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_')->substr(0, 57)->toString();
    }
}
