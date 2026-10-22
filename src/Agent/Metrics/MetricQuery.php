<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Metrics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

/**
 * Builds the SQL behind a metric: the host's base query, narrowed to a period,
 * aggregated, optionally grouped by a dimension or by a date bucket.
 *
 * Everything that reaches SQL as text comes from the host's definition --
 * column names, expressions, join callbacks -- never from the model's
 * arguments, which only choose among names the definition declared.
 */
final class MetricQuery
{
    public const BUCKETS = ['day', 'week', 'month', 'year'];

    /** @var array<string, string> relation path => joined table alias */
    private array $joined = [];

    private QueryBuilder $query;

    private Model $model;

    /**
     * @param  Builder<Model>  $base
     */
    public function __construct(Builder $base, private readonly MetricDefinition $definition, Period $period)
    {
        $this->model = $base->getModel();
        $base = clone $base;

        if (($date = $definition->getDate()) !== null) {
            $base->whereBetween($this->column($date), [$period->from->toDateTimeString(), $period->to->toDateTimeString()]);
        }

        // toBase() applies the model's global scopes (soft deletes included).
        $this->query = $base->toBase();
    }

    /**
     * @param  list<Measure>  $measures
     * @return array<string, int|float>
     */
    public function totals(array $measures): array
    {
        $row = (clone $this->query)->select($this->aggregates($measures))->first();

        return $this->values($measures, (array) $row);
    }

    /**
     * @param  list<Measure>  $measures
     * @return list<array<string, mixed>>
     */
    public function groups(Dimension $dimension, array $measures, Measure $sortBy, int $limit): array
    {
        $query = clone $this->query;
        $key = $this->dimensionExpression($query, $dimension);

        $rows = $query
            ->select([new Expression($key.' as fai_key'), ...$this->aggregates($measures)])
            ->groupBy(new Expression($key))
            ->orderByDesc('m_'.$sortBy->name)
            ->limit($limit)
            ->get();

        return $rows->map(fn (object $row): array => [
            $dimension->name => ((array) $row)['fai_key'] ?? null,
            ...$this->values($measures, (array) $row),
        ])->all();
    }

    /**
     * @param  list<Measure>  $measures
     * @return list<array<string, mixed>>
     */
    public function series(string $bucket, array $measures): array
    {
        $date = $this->definition->getDate() ?? throw new InvalidArgumentException('A series needs the metric\'s date column.');
        $expression = $this->bucketExpression($this->column($date), $bucket);

        $rows = (clone $this->query)
            ->select([new Expression($expression.' as fai_bucket'), ...$this->aggregates($measures)])
            ->groupBy(new Expression($expression))
            ->orderBy('fai_bucket')
            ->get();

        return $rows->map(fn (object $row): array => [
            $bucket => (string) ((array) $row)['fai_bucket'],
            ...$this->values($measures, (array) $row),
        ])->all();
    }

    /**
     * @param  list<Measure>  $measures
     * @return list<Expression>
     */
    private function aggregates(array $measures): array
    {
        $grammar = $this->query->getGrammar();
        $select = [];

        foreach ($measures as $measure) {
            $target = $measure->column === null ? '*' : $this->sql($measure->column);
            $function = strtoupper($measure->function);
            $inner = $measure->distinct ? "DISTINCT {$target}" : $target;

            $select[] = new Expression("{$function}({$inner}) as ".$grammar->wrap('m_'.$measure->name));
        }

        return $select;
    }

    /**
     * @param  list<Measure>  $measures
     * @param  array<string, mixed>  $row
     * @return array<string, int|float>
     */
    private function values(array $measures, array $row): array
    {
        $values = [];

        foreach ($measures as $measure) {
            $values[$measure->name] = $measure->value($row['m_'.$measure->name] ?? null);
        }

        return $values;
    }

    private function dimensionExpression(QueryBuilder $query, Dimension $dimension): string
    {
        if ($dimension->join !== null) {
            ($dimension->join)($query);

            return $this->sql($dimension->path);
        }

        $parts = explode('.', $dimension->path);
        $column = array_pop($parts);

        if ($parts === [] || ! $this->model->isRelation($parts[0])) {
            return $this->sql($dimension->path);
        }

        $alias = $this->joinRelations($query, $parts);

        return $query->getGrammar()->wrap($alias.'.'.$column);
    }

    /**
     * Left-joins a chain of belongs-to relationships, each under its own
     * alias so a table reached twice ("user", "item.user") does not clash.
     *
     * @param  list<string>  $relations
     */
    private function joinRelations(QueryBuilder $query, array $relations): string
    {
        $model = $this->model;
        $parentAlias = $model->getTable();
        $path = [];

        foreach ($relations as $name) {
            $relation = $model->isRelation($name) ? $model->{$name}() : null;

            if (! $relation instanceof BelongsTo) {
                throw new InvalidArgumentException("Metric dimensions join belongs-to relationships only; [{$name}] on [".$model::class.'] is not one. Pass a join callback instead.');
            }

            $path[] = $name;
            $alias = 'fai_'.implode('_', $path);
            $related = $relation->getRelated();

            if (! isset($this->joined[$alias])) {
                $query->leftJoin(
                    $related->getTable().' as '.$alias,
                    $alias.'.'.$relation->getOwnerKeyName(),
                    '=',
                    $parentAlias.'.'.$relation->getForeignKeyName(),
                );
                $this->joined[$alias] = $alias;
            }

            $model = $related;
            $parentAlias = $alias;
        }

        return $parentAlias;
    }

    /**
     * A plain column name is qualified with the model's table and wrapped;
     * anything else is the host's own SQL expression, used as written.
     */
    private function sql(string $column): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            return $column;
        }

        return $this->query->getGrammar()->wrap($this->column($column));
    }

    private function column(string $column): string
    {
        return str_contains($column, '.') ? $column : $this->model->qualifyColumn($column);
    }

    private function bucketExpression(string $column, string $bucket): string
    {
        $wrapped = $this->query->getGrammar()->wrap($column);

        return match ($this->query->getConnection()->getDriverName()) {
            'sqlite' => match ($bucket) {
                'day' => "strftime('%Y-%m-%d', {$wrapped})",
                // SQLite has no ISO week: Monday-based week of the year.
                'week' => "strftime('%Y-W%W', {$wrapped})",
                'month' => "strftime('%Y-%m', {$wrapped})",
                'year' => "strftime('%Y', {$wrapped})",
            },
            'pgsql' => match ($bucket) {
                'day' => "to_char({$wrapped}, 'YYYY-MM-DD')",
                'week' => "to_char({$wrapped}, 'IYYY-\"W\"IW')",
                'month' => "to_char({$wrapped}, 'YYYY-MM')",
                'year' => "to_char({$wrapped}, 'YYYY')",
            },
            'mysql', 'mariadb' => match ($bucket) {
                'day' => "DATE_FORMAT({$wrapped}, '%Y-%m-%d')",
                'week' => "DATE_FORMAT({$wrapped}, '%x-W%v')",
                'month' => "DATE_FORMAT({$wrapped}, '%Y-%m')",
                'year' => "DATE_FORMAT({$wrapped}, '%Y')",
            },
            default => throw new InvalidArgumentException('Metric series are not supported on this database.'),
        };
    }
}
