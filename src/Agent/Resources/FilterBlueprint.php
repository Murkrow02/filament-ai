<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Tables\Filters\BaseFilter;

/**
 * One of the table's filters, as list tool arguments.
 *
 * The filter object itself is carried along: narrowing is done by handing the
 * value back to Filament's own `apply()`, so a relationship filter, a scope or
 * a custom query callback behaves exactly as it does in the table instead of
 * being re-implemented as SQL here.
 *
 * Three shapes: a select (ENUM or TEXT, one argument), a yes/no (BOOLEAN: a
 * ternary filter, or a plain toggle filter that only narrows when true), and
 * a filter with its own form (FORM: one argument per supported field).
 */
final readonly class FilterBlueprint
{
    public const ENUM = 'enum';

    public const BOOLEAN = 'boolean';

    public const TEXT = 'text';

    public const FORM = 'form';

    /**
     * @param  array<string, string>|null  $options  value => label
     * @param  bool  $toggle  a plain filter whose only state is "active"
     * @param  list<FilterField>  $fields  the form's fields, for FORM
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public BaseFilter $filter,
        public ?array $options = null,
        public bool $multiple = false,
        public bool $toggle = false,
        public array $fields = [],
    ) {}

    /**
     * The argument names this filter adds to the list tool.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->type === self::FORM
            ? array_map(static fn (FilterField $field): string => $field->argument, $this->fields)
            : [$this->name];
    }

    /**
     * The filter state built from the tool's arguments, or null when none of
     * them was given (or a toggle was given as false): the filter then does
     * not run at all.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public function stateFrom(array $arguments): ?array
    {
        if ($this->type === self::FORM) {
            $state = [];

            foreach ($this->fields as $field) {
                $value = $arguments[$field->argument] ?? null;

                if ($value !== null && $value !== '' && $value !== []) {
                    data_set($state, $field->statePath, $value);
                }
            }

            return $state === [] ? null : $state;
        }

        $value = $arguments[$this->name] ?? null;

        if ($value === null) {
            return null;
        }

        if ($this->toggle) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? ['isActive' => true] : null;
        }

        return $this->state($value);
    }

    /**
     * The state shape Filament's filters read: a select keeps one `value`, a
     * multiple select keeps `values`.
     */
    public function state(mixed $value): array
    {
        return $this->multiple
            ? ['values' => array_values((array) $value)]
            : ['value' => $value];
    }

    public function description(): string
    {
        $description = $this->label;

        if ($this->toggle) {
            return $description.'. Pass true to keep only these records.';
        }

        if ($this->options !== null && $this->options !== []) {
            $description .= '. One of: '.self::choices($this->options);
        }

        return $description.'.';
    }

    /**
     * @param  array<string, string>  $options
     */
    public static function choices(array $options): string
    {
        $choices = [];

        foreach ($options as $value => $label) {
            $choices[] = (string) $value === $label ? (string) $value : "{$value} = {$label}";
        }

        return implode(', ', $choices);
    }
}
