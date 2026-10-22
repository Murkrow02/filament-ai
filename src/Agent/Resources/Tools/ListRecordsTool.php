<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Filament\Tables\Columns\Column;
use Illuminate\JsonSchema\Types\Type;
use Murkrow\FilamentAi\Agent\Resources\FilterBlueprint;
use Murkrow\FilamentAi\Agent\Resources\FilterField;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Tools\Concerns\GuardsToolFailures;
use Throwable;
use Murkrow\FilamentAi\Agent\Resources\AgentQuery;
use Illuminate\Contracts\Support\Htmlable;

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
    use GuardsToolFailures;

    /** Longer than anybody types into a search box, short enough to stay cheap. */
    private const MAX_SEARCH = 200;

    public function __construct(private readonly ResourceBlueprint $blueprint) {}

    public function name(): string
    {
        return $this->blueprint->toolPrefix.'_list';
    }

    public function description(): string
    {
        $text = "List {$this->blueprint->pluralLabel} from the application, newest first unless sorted.";

        if ($this->blueprint->searchColumns !== []) {
            $text .= ' Pass "search" to match text in: '.implode(', ', $this->blueprint->searchColumns).'.';
        }

        if ($this->blueprint->filters !== []) {
            $text .= ' Narrow it with: '.implode(', ', array_merge(...array_map(
                static fn (FilterBlueprint $filter): array => $filter->arguments(),
                $this->blueprint->filters,
            ))).'.';
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

        if ($this->blueprint->sortColumns !== []) {
            $fields['sort'] = $schema->string()->enum(array_map(strval(...), array_keys($this->blueprint->sortColumns)))
                ->description('Column to order by: '.$this->sortChoices().'. Omit for newest first.');
            $fields['direction'] = $schema->string()->enum(['asc', 'desc'])->description('Order of "sort": asc (default) or desc.');
        }

        foreach ($this->blueprint->filters as $filter) {
            if ($filter->type === FilterBlueprint::FORM) {
                foreach ($filter->fields as $field) {
                    $fields[$field->argument] = $this->fieldType($schema, $field)->description($field->description());
                }

                continue;
            }

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

    private function fieldType(JsonSchema $schema, FilterField $field): Type
    {
        return match ($field->type) {
            FilterField::BOOLEAN => $schema->boolean(),
            FilterField::NUMBER => $schema->number(),
            FilterField::ENUM => $field->multiple
                ? $schema->array()->items($schema->string()->enum(array_map(strval(...), array_keys($field->options ?? []))))
                : $schema->string()->enum(array_map(strval(...), array_keys($field->options ?? []))),
            default => $schema->string(),
        };
    }

    private function sortChoices(): string
    {
        $choices = [];

        foreach ($this->blueprint->sortColumns as $name => $column) {
            try {
                $label = $column->getLabel();
                $label = $label instanceof Htmlable ? trim(strip_tags($label->toHtml())) : (string) $label;
            } catch (Throwable) {
                $label = '';
            }

            $choices[] = $label === '' || $label === $name ? $name : "{$name} ({$label})";
        }

        return implode(', ', $choices);
    }

    public function handle(Request $request): string
    {
        return $this->guarded(fn (): string => $this->list($request));
    }

    private function list(Request $request): string
    {
        $resource = $this->blueprint->resource;

        if (! $resource::canViewAny()) {
            return "Error: the current user is not allowed to view {$this->blueprint->pluralLabel}.";
        }

        $arguments = $request->all();
        $perPage = max(1, min($this->blueprint->maxRecords, (int) ($arguments['per_page'] ?? $this->blueprint->maxRecords)));
        $page = max(1, (int) ($arguments['page'] ?? 1));

        $query = AgentQuery::for($resource);
        $search = is_scalar($arguments['search'] ?? null) ? trim((string) $arguments['search']) : '';
        $this->applySearch($query, mb_substr($search, 0, self::MAX_SEARCH));

        foreach ($this->blueprint->filters as $filter) {
            $state = $filter->stateFrom($arguments);

            if ($state === null) {
                continue;
            }

            try {
                // Filament's own filter: a relationship, a scope or a custom
                // query behaves exactly as it does in the table.
                $query = $filter->filter->apply($query, $state);
            } catch (Throwable $exception) {
                report($exception);

                // Saying so beats answering with unfiltered rows the model
                // would report as filtered.
                return "Error: the [{$filter->name}] filter cannot be applied here. Try listing without it.";
            }
        }

        $total = (clone $query)->count();

        $sort = is_string($arguments['sort'] ?? null) ? ($this->blueprint->sortColumns[$arguments['sort']] ?? null) : null;

        if ($sort instanceof Column) {
            try {
                $query = $sort->applySort($query, ($arguments['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
            } catch (Throwable $exception) {
                report($exception);

                return "Error: the records cannot be sorted by [{$arguments['sort']}]. Try without sorting.";
            }
        }

        $records = $query
            ->with($this->relationsToLoad($query->getModel()))
            // The key last: a stable order between pages when sorted values tie.
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

        // PostgreSQL's LIKE is case-sensitive, and refuses a non-text column
        // outright; every other supported driver's is neither. The column is
        // cast so a searchable id or number behaves the same everywhere.
        $pgsql = $query->getConnection()->getDriverName() === 'pgsql';
        $operator = $pgsql ? 'ilike' : 'like';

        // What the model typed is text to find, not a pattern: an underscore
        // in a customer code must not match every character. `!` rather than
        // a backslash as the escape: MySQL reads a backslash inside the
        // ESCAPE literal as escaping the closing quote.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        $query->where(function (Builder $where) use ($operator, $pattern, $pgsql): void {
            foreach ($this->blueprint->searchColumns as $column) {
                if (! str_contains($column, '.')) {
                    $this->whereLike($where, $column, $operator, $pattern, $pgsql, 'or');

                    continue;
                }

                // "itemData.name": the related record's column, through the
                // relationship, so a record is found by its related name.
                $relation = substr($column, 0, (int) strrpos($column, '.'));
                $related = substr($column, (int) strrpos($column, '.') + 1);

                $where->orWhereHas($relation, fn (Builder $inner) => $this->whereLike($inner, $related, $operator, $pattern, $pgsql, 'and'));
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function whereLike(Builder $query, string $column, string $operator, string $pattern, bool $pgsql, string $boolean): void
    {
        $qualified = $query->getModel()->qualifyColumn($column);
        $grammar = $query->getQuery()->getGrammar();

        $query->whereRaw(
            ($pgsql ? 'CAST('.$grammar->wrap($qualified).' AS TEXT)' : $grammar->wrap($qualified))." {$operator} ? ESCAPE '!'",
            [$pattern],
            $boolean,
        );
    }

    /**
     * The relations the listed attributes reach through, loaded up front so
     * a page of records costs one query per relation instead of one per row.
     *
     * @return list<string>
     */
    private function relationsToLoad(Model $model): array
    {
        $relations = [];

        foreach ($this->blueprint->listAttributes as $attribute) {
            if (! str_contains($attribute, '.')) {
                continue;
            }

            $relation = substr($attribute, 0, (int) strrpos($attribute, '.'));

            if ($model->isRelation(explode('.', $relation)[0])) {
                $relations[] = $relation;
            }
        }

        return array_values(array_unique($relations));
    }
}
