<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\FilterBlueprint;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Throwable;

/**
 * Lists or searches the records of one resource, as the current panel user.
 *
 * The query starts from `Resource::getEloquentQuery()`, so tenant scoping and
 * whatever the host narrowed there apply exactly as in the table, and the
 * resource's `viewAny` policy is checked on every call, not at registration:
 * the same tool list may outlive a permission change within a conversation.
 */
final class ListRecordsTool implements Tool
{
    public function __construct(private readonly ResourceBlueprint $blueprint) {}

    public function name(): string
    {
        return $this->blueprint->toolPrefix.'_list';
    }

    public function description(): string
    {
        $text = "List {$this->blueprint->pluralLabel} from the application, newest first.";

        if ($this->blueprint->searchColumns !== []) {
            $text .= ' Pass "search" to match text in: '.implode(', ', $this->blueprint->searchColumns).'.';
        }

        if ($this->blueprint->filters !== []) {
            $text .= ' Narrow it with: '.implode(', ', array_map(
                static fn (FilterBlueprint $filter): string => $filter->name,
                $this->blueprint->filters,
            )).'.';
        }

        $text .= ' Returns the total count and one page of records with their id, title and link.';

        return $this->blueprint->description === null ? $text : $this->blueprint->description.' '.$text;
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [
            'page' => $schema->integer()->description('Page number, starting at 1.'),
            'per_page' => $schema->integer()->description("Records per page, at most {$this->blueprint->maxRecords}."),
        ];

        if ($this->blueprint->searchColumns !== []) {
            $fields['search'] = $schema->string()->description('Free text to look for. Omit to list everything.');
        }

        foreach ($this->blueprint->filters as $filter) {
            $type = match ($filter->type) {
                FilterBlueprint::BOOLEAN => $schema->boolean(),
                FilterBlueprint::ENUM => $filter->multiple
                    ? $schema->array()->items($schema->string()->enum(array_map(strval(...), array_keys($filter->options ?? []))))
                    : $schema->string()->enum(array_map(strval(...), array_keys($filter->options ?? []))),
                default => $filter->multiple ? $schema->array()->items($schema->string()) : $schema->string(),
            };

            $fields[$filter->name] = $type->description($filter->description());
        }

        return $fields;
    }

    public function handle(Request $request): string
    {
        $resource = $this->blueprint->resource;

        if (! $resource::canViewAny()) {
            return "Error: the current user is not allowed to view {$this->blueprint->pluralLabel}.";
        }

        $arguments = $request->all();
        $perPage = max(1, min($this->blueprint->maxRecords, (int) ($arguments['per_page'] ?? $this->blueprint->maxRecords)));
        $page = max(1, (int) ($arguments['page'] ?? 1));

        $query = $resource::getEloquentQuery();
        $this->applySearch($query, trim((string) ($arguments['search'] ?? '')));

        foreach ($this->blueprint->filters as $filter) {
            if (! array_key_exists($filter->name, $arguments) || $arguments[$filter->name] === null) {
                continue;
            }

            try {
                // Filament's own filter: a relationship, a scope or a custom
                // query behaves exactly as it does in the table.
                $query = $filter->filter->apply($query, $filter->state($arguments[$filter->name]));
            } catch (Throwable) {
                // Saying so beats answering with unfiltered rows the model
                // would report as filtered.
                return "Error: the [{$filter->name}] filter cannot be applied here. Try listing without it.";
            }
        }

        $total = (clone $query)->count();

        $records = $query
            ->orderByDesc($query->getModel()->getQualifiedKeyName())
            ->forPage($page, $perPage)
            ->get();

        return json_encode([
            'resource' => $this->blueprint->pluralLabel,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'records' => $records
                ->map(fn (Model $record): array => RecordPresenter::present($record, $this->blueprint, $this->blueprint->listAttributes))
                ->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '' || $this->blueprint->searchColumns === []) {
            return;
        }

        // PostgreSQL's LIKE is case-sensitive; every other supported driver's
        // is not. A search the user types must behave the same on both.
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $model = $query->getModel();

        $query->where(function (Builder $where) use ($model, $operator, $search): void {
            foreach ($this->blueprint->searchColumns as $column) {
                $where->orWhere($model->qualifyColumn($column), $operator, '%'.$search.'%');
            }
        });
    }
}
