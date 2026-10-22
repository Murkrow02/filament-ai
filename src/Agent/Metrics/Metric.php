<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Metrics;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Agent\Resources\FilterBlueprint;
use Murkrow\FilamentAi\Agent\Tools\AgentTool;
use Murkrow\FilamentAi\Agent\Tools\ToolRefusal;

/**
 * A figure the assistant can compute and follow over time -- revenue, pieces
 * sold, new customers -- declared once by the application and offered as one
 * tool. Opt-in: list the classes in `filament-ai.agent.metrics`.
 *
 *     final class SalesMetric extends Metric
 *     {
 *         public function query(): Builder
 *         {
 *             return Sale::query()->where('status', 'completed');
 *         }
 *
 *         public function define(MetricDefinition $metric): void
 *         {
 *             $metric->label('Vendite')
 *                 ->date('sale_date')
 *                 ->count('sales')
 *                 ->sum('revenue', 'total_price', unit: 'EUR', divisor: 100)
 *                 ->dimension('operator', 'user.name')
 *                 ->filter('store', 'Store id', fn (Builder $q, $id) => $q->where('store_id', $id));
 *         }
 *     }
 *
 * One call answers "how much this week", "who sold most in September" (group
 * by, top N), "compare September with August" (compare) and "how did it go
 * month by month" (a series by day, week, month or year). The model never
 * writes SQL: it picks among the names the definition declared.
 */
abstract class Metric extends AgentTool
{
    /** Arguments every metric has; a filter cannot take these names. */
    public const RESERVED = ['period', 'from', 'to', 'compare', 'group_by', 'series', 'sort_by', 'limit'];

    public const MAX_GROUPS = 50;

    private ?MetricDefinition $definition = null;

    /**
     * Every record the metric may count, for the current user: scoped the way
     * the application scopes them, with whatever must never be counted
     * already left out.
     *
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    abstract public function query(): Builder;

    abstract public function define(MetricDefinition $metric): void;

    public function definition(): MetricDefinition
    {
        if ($this->definition === null) {
            $this->definition = new MetricDefinition;
            $this->define($this->definition);
        }

        return $this->definition;
    }

    public function name(): string
    {
        return Str::of(class_basename(static::class))->beforeLast('Metric')->snake()->prepend('metric_')->toString();
    }

    public function chatLabel(): string
    {
        return (string) __('filament-ai::messages.tools.metric', ['label' => $this->label()]);
    }

    public function description(): string
    {
        $definition = $this->definition();
        $measures = array_map(
            static fn (Measure $measure): string => $measure->name.($measure->unit === null ? '' : " ({$measure->unit})").($measure->label === Str::headline($measure->name) ? '' : ": {$measure->label}"),
            array_values($definition->measures()),
        );

        $text = "{$this->label()}: ".implode(', ', $measures).'.';

        if (($description = $definition->getDescription()) !== null) {
            $text .= ' '.$description;
        }

        return $text.' Computes totals for a period, optionally compared with the previous period or the same period last year, '
            .'broken down by '.($definition->dimensions() === [] ? 'nothing' : implode(', ', array_keys($definition->dimensions())))
            .($definition->getDate() === null ? '' : ', or as a series by day, week, month or year')
            .'. Use it for any total, average, ranking or trend instead of adding up listed records.';
    }

    public function schema(JsonSchema $schema): array
    {
        $definition = $this->definition();
        $fields = [];

        if ($definition->getDate() !== null) {
            $named = array_keys($definition->namedPeriods());

            $fields['period'] = $schema->string()->enum([...Periods::PRESETS, ...$named])
                ->description('The period, when it is one of these'.($named === [] ? '' : ' (the last ones are the application\'s own periods)').'. Default this_month. Running periods (this_week, this_month, this_year) stop at today.');
            $fields['from'] = $schema->string()->description('First day, YYYY-MM-DD, for any other period (e.g. a month: its first day). Overrides "period".');
            $fields['to'] = $schema->string()->description('Last day, YYYY-MM-DD, included (e.g. a month: its last day).');
            $fields['compare'] = $schema->string()->enum(['previous_period', 'previous_year'])
                ->description('Also compute the period before (a month: the month before; this month so far: the same days of last month) or the same period one year earlier, with the differences.');
        }

        if ($definition->dimensions() !== []) {
            $fields['group_by'] = $schema->string()->enum(array_keys($definition->dimensions()))
                ->description('Break the figures down by: '.implode(', ', array_map(static fn (Dimension $d): string => $d->name.($d->label === Str::headline($d->name) ? '' : " ({$d->label})"), $definition->dimensions())).'. Returns the top groups.');
            $fields['sort_by'] = $schema->string()->enum(array_keys($definition->measures()))
                ->description('With group_by: the measure the groups are ranked by, highest first. Default the first one.');
            $fields['limit'] = $schema->integer()->description('With group_by: how many groups, at most '.self::MAX_GROUPS.'. Default 10.');
        }

        if ($definition->getDate() !== null) {
            $fields['series'] = $schema->string()->enum(MetricQuery::BUCKETS)
                ->description('Also return the figures over time, one row per day, week, month or year of the period.');
        }

        foreach ($definition->filters() as $filter) {
            $options = $filter->options();
            $type = $options === null ? $schema->string() : $schema->string()->enum(array_map(strval(...), array_keys($options)));

            $fields[$filter->name] = $type->description($filter->description.($options === null ? '' : '. One of: '.FilterBlueprint::choices($options)).'.');
        }

        return $fields;
    }

    protected function label(): string
    {
        return $this->definition()->getLabel() ?? Str::headline(class_basename(static::class));
    }

    protected function run(array $arguments): array|string
    {
        $definition = $this->definition();
        $measures = array_values($definition->measures());

        if ($measures === []) {
            throw new ToolRefusal('this metric has nothing the current user may see.');
        }

        $period = $definition->getDate() === null
            ? null
            : app(Periods::class)->resolve(
                $this->string($arguments, 'period'),
                $this->string($arguments, 'from'),
                $this->string($arguments, 'to'),
                $definition->namedPeriods(),
            );

        $dimension = null;

        if (($groupBy = $this->string($arguments, 'group_by')) !== null) {
            $dimension = $definition->dimensions()[$groupBy] ?? throw new ToolRefusal("cannot group by [{$groupBy}]. Use one of: ".implode(', ', array_keys($definition->dimensions())).'.');
        }

        $series = $this->string($arguments, 'series');

        if ($series !== null && ($period === null || ! in_array($series, MetricQuery::BUCKETS, true))) {
            throw new ToolRefusal('"series" must be one of: '.implode(', ', MetricQuery::BUCKETS).'.');
        }

        $sortBy = $definition->measures()[$this->string($arguments, 'sort_by') ?? $measures[0]->name] ?? $measures[0];
        $limit = max(1, min(self::MAX_GROUPS, (int) ($arguments['limit'] ?? 10)));

        $result = [
            'metric' => $this->label(),
            'units' => array_filter(array_map(static fn (Measure $m): ?string => $m->unit, $definition->measures())),
        ];

        $compute = function (?Period $period) use ($arguments, $measures, $dimension, $sortBy, $limit, $series): array {
            $query = $this->narrowed($arguments, $period);
            $figures = ['totals' => $query->totals($measures)];

            if ($dimension !== null) {
                $figures['groups'] = $query->groups($dimension, $measures, $sortBy, $limit);
            }

            if ($series !== null) {
                $figures['series'] = $query->series($series, $measures);
            }

            return $figures;
        };

        if ($period !== null) {
            $result['period'] = $period->toArray();
        }

        $result = [...$result, ...$compute($period)];

        $compare = $this->string($arguments, 'compare');

        if ($period !== null && $compare !== null) {
            $previous = match ($compare) {
                'previous_year' => $period->previousYear(),
                'previous_period' => $period->previous(),
                default => throw new ToolRefusal('"compare" must be previous_period or previous_year.'),
            };

            $before = $compute($previous);
            $result['compared_with'] = [
                'period' => $previous->toArray(),
                'totals' => $before['totals'],
                'change' => $this->changes($result['totals'], $before['totals']),
            ];

            if ($dimension !== null) {
                $result['compared_with']['groups'] = $before['groups'];
            }
        }

        return $result;
    }

    private function narrowed(array $arguments, ?Period $period): MetricQuery
    {
        $query = $this->query();

        foreach ($this->definition()->filters() as $filter) {
            $value = $arguments[$filter->name] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $options = $filter->options();

            if ($options !== null && ! array_key_exists((string) $value, $options)) {
                throw new ToolRefusal("[{$value}] is not a valid {$filter->name}.");
            }

            ($filter->apply)($query, $value);
        }

        return new MetricQuery($query, $this->definition(), $period ?? new Period(now()->toImmutable()->startOfDay(), now()->toImmutable()->endOfDay(), 'all'));
    }

    /**
     * @param  array<string, int|float>  $now
     * @param  array<string, int|float>  $before
     * @return array<string, array{difference: int|float, percent: float|null}>
     */
    private function changes(array $now, array $before): array
    {
        $changes = [];

        foreach ($now as $name => $value) {
            $previous = $before[$name] ?? 0;

            $changes[$name] = [
                'difference' => is_int($value) && is_int($previous) ? $value - $previous : round($value - $previous, 2),
                'percent' => $previous == 0 ? null : round(($value - $previous) / abs($previous) * 100, 1),
            ];
        }

        return $changes;
    }

    private function string(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
