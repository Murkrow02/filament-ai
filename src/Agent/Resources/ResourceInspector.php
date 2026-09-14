<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Resources\Pages\PageRegistration;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads what a Filament resource already declares and turns it into a
 * `ResourceBlueprint` for the agent tools.
 *
 * A resource's table and form are configured against a Livewire component, and
 * none is mounted when the agent runs. The table is therefore built against an
 * unmounted instance of the resource's own table page, and the form against no
 * component at all. Only static facts are read -- names, searchability -- never
 * state. Anything that cannot be read degrades to "not derived" rather than
 * failing: a resource with an exotic table still gets a working tool, just a
 * less informed one.
 */
final class ResourceInspector
{
    /** @var array<string, list<string>> */
    private array $columnListings = [];

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public function inspect(string $resource, AgentTools $tools): ResourceBlueprint
    {
        /** @var Model $model */
        $model = app($resource::getModel());
        $columns = $this->tableColumns($resource);
        $tableNames = array_values(array_map(static fn (Column $column): string => $column->getName(), $columns));
        $formNames = $this->formFieldNames($resource);

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
        );
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return list<Column>
     */
    private function tableColumns(string $resource): array
    {
        $livewire = $this->tablePage($resource);

        if ($livewire === null) {
            return [];
        }

        try {
            $table = $resource::table(Table::make($livewire));
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            $table->getColumns(),
            static fn (mixed $column): bool => $column instanceof Column,
        ));
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private function tablePage(string $resource): ?HasTable
    {
        foreach ($resource::getPages() as $registration) {
            $page = $registration instanceof PageRegistration ? $registration->getPage() : null;

            if ($page !== null && is_subclass_of($page, HasTable::class)) {
                try {
                    return app($page);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return list<string>
     */
    private function formFieldNames(string $resource): array
    {
        try {
            $fields = $resource::form(Schema::make())->getFlatFields(withHidden: true);
        } catch (Throwable) {
            return [];
        }

        $names = [];

        foreach ($fields as $field) {
            try {
                $names[] = $field->getName();
            } catch (Throwable) {
                continue;
            }
        }

        return array_values(array_unique($names));
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
        // the longest suffix appended is "_list".
        return Str::of($slug)->replaceMatches('/[^A-Za-z0-9]+/', '_')->trim('_')->substr(0, 58)->toString();
    }
}
