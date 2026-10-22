<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Maps a Filament table filter to list tool arguments.
 *
 * Selects carry their own choices and their own query, so the agent narrows
 * exactly the way the table does. A plain filter is a yes/no switch. A filter
 * with its own form ("sold between": two date pickers) offers each field it
 * can describe -- dates, text, numbers, switches, static selects -- and the
 * values are put back where the form would keep them before Filament's own
 * query runs. A form with nothing describable is left out rather than guessed
 * at. Trashed records are the resource's business (`agentQuery()`), so the
 * trashed filter is never offered.
 */
final class TableFilterMapper
{
    /**
     * Arguments the list tool already defines. A filter that happens to be
     * named like one of them is skipped instead of shadowing it.
     */
    public const RESERVED = ['page', 'per_page', 'search', 'sort', 'direction'];

    private const MAX_OPTIONS = 100;

    public function map(BaseFilter $filter): ?FilterBlueprint
    {
        if ($filter instanceof TrashedFilter) {
            return null;
        }

        $name = $this->attempt(fn (): string => $filter->getName(), '');

        if ($name === '' || str_contains($name, '.') || in_array($name, self::RESERVED, true)) {
            return null;
        }

        $label = $this->label($filter, $name);

        // TernaryFilter extends SelectFilter, so it is checked first.
        if ($filter instanceof TernaryFilter) {
            return new FilterBlueprint($name, $label, FilterBlueprint::BOOLEAN, $filter);
        }

        if ($filter instanceof SelectFilter) {
            return $this->select($filter, $name, $label);
        }

        if ($filter instanceof Filter && ! $this->attempt(fn (): bool => $filter->hasSchema(), true)) {
            return new FilterBlueprint($name, $label, FilterBlueprint::BOOLEAN, $filter, toggle: true);
        }

        return $this->form($filter, $name, $label);
    }

    private function select(SelectFilter $filter, string $name, string $label): ?FilterBlueprint
    {
        $multiple = $this->attempt(fn (): bool => $filter->isMultiple(), false);
        $relationship = $this->attempt(fn (): bool => $filter->queriesRelationships(), false);
        $options = $this->attempt(fn (): array => $filter->getOptions(), []);

        if ($options === [] && $relationship) {
            $options = $this->attempt(fn (): array => $this->relatedOptions($filter), []);
        }

        $options = $this->options($options);

        if ($options === null) {
            // Too many choices to list: the argument survives as the related
            // record's key, which the model finds with that resource's list tool.
            return $relationship
                ? new FilterBlueprint($name, $label.' (id)', FilterBlueprint::TEXT, $filter, multiple: $multiple)
                : null;
        }

        return new FilterBlueprint($name, $label, FilterBlueprint::ENUM, $filter, $options, $multiple);
    }

    /**
     * A relationship select that is not preloaded searches its options as
     * the user types, so it has none to give. Short lists are read here
     * through the filter's own relationship query (its constraints and
     * order included): "operator = 3 = Lucia" beats an id the model would
     * have to look up first.
     *
     * @return array<string, string>
     */
    private function relatedOptions(SelectFilter $filter): array
    {
        $query = $filter->getRelationshipQuery();
        $title = $filter->getRelationshipTitleAttribute();
        $key = $filter->getRelationshipKey($query);

        if ($query === null || blank($title) || blank($key)) {
            return [];
        }

        return $query->limit(self::MAX_OPTIONS + 1)->pluck($query->qualifyColumn($title), $key)->all();
    }

    private function form(BaseFilter $filter, string $name, string $label): ?FilterBlueprint
    {
        $components = array_values(array_filter(
            $this->attempt(fn (): array => $filter->getSchemaComponents(), []),
            static fn (mixed $component): bool => $component instanceof Field,
        ));

        $fields = [];

        foreach ($components as $component) {
            $field = $this->field($component, count($components) === 1 ? $name : $name.'_'.$component->getName(), $label);

            if ($field !== null && ! in_array($field->argument, self::RESERVED, true)) {
                $fields[] = $field;
            }
        }

        return $fields === []
            ? null
            : new FilterBlueprint($name, $label, FilterBlueprint::FORM, $filter, fields: $fields);
    }

    private function field(Field $component, string $argument, string $filterLabel): ?FilterField
    {
        $statePath = $this->attempt(fn (): string => $component->getName(), '');

        if ($statePath === '') {
            return null;
        }

        $fieldLabel = $this->plain($this->attempt(fn (): string|Htmlable|null => $component->getLabel(), null) ?? Str::headline($statePath));
        $label = $fieldLabel === '' || $fieldLabel === $filterLabel ? $filterLabel : "{$filterLabel}: {$fieldLabel}";

        return match (true) {
            // DatePicker extends DateTimePicker, so it is checked first.
            $component instanceof DatePicker => new FilterField($argument, $statePath, $label, FilterField::DATE),
            $component instanceof DateTimePicker => new FilterField(
                $argument,
                $statePath,
                $label,
                $this->attempt(fn (): bool => $component->hasTime(), true) ? FilterField::DATETIME : FilterField::DATE,
            ),
            $component instanceof Toggle, $component instanceof Checkbox => new FilterField($argument, $statePath, $label, FilterField::BOOLEAN),
            $component instanceof TextInput => new FilterField(
                $argument,
                $statePath,
                $label,
                $this->attempt(fn (): bool => $component->isNumeric(), false) ? FilterField::NUMBER : FilterField::STRING,
            ),
            $component instanceof Select => $this->selectField($component, $argument, $statePath, $label),
            default => null,
        };
    }

    private function selectField(Select $component, string $argument, string $statePath, string $label): ?FilterField
    {
        $options = $this->options($this->attempt(fn (): array => $component->getOptions(), []));

        return $options === null
            ? null
            : new FilterField($argument, $statePath, $label, FilterField::ENUM, $options, $this->attempt(fn (): bool => $component->isMultiple(), false));
    }

    /**
     * @param  array<mixed>  $options
     * @return array<string, string>|null
     */
    private function options(array $options): ?array
    {
        $flat = [];

        foreach ($options as $value => $label) {
            if (is_array($label)) {
                foreach ($label as $groupValue => $groupLabel) {
                    $flat[(string) $groupValue] = $this->plain($groupLabel);
                }

                continue;
            }

            $flat[(string) $value] = $this->plain($label);
        }

        return $flat === [] || count($flat) > self::MAX_OPTIONS ? null : $flat;
    }

    private function label(BaseFilter $filter, string $name): string
    {
        $label = $this->attempt(fn (): string|Htmlable|null => $filter->getLabel(), null);

        return $label === null || $label === '' ? Str::headline($name) : $this->plain($label);
    }

    private function plain(mixed $value): string
    {
        return $value instanceof Htmlable ? trim(strip_tags($value->toHtml())) : (string) $value;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $default
     * @return T
     */
    private function attempt(callable $callback, mixed $default): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $default;
        }
    }
}
