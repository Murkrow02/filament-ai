<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Forms\Components\Field;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Maps a Filament form field to a write tool argument.
 *
 * Only describes: which fields are offered at all, and whether they are
 * enforced, is `FormPipeline`'s business -- it runs the form itself.
 *
 * Keyed by `instanceof`, like `SourceFilterSchema`: a field class this does not
 * know degrades to a plain string, and fields whose value cannot be written as
 * a single attribute (uploads, repeaters, many-to-many selects, ...) are left
 * out rather than half-supported. Class names are compared as strings, so a
 * Filament release that drops one of them turns that branch off instead of
 * failing to autoload.
 */
final class FormFieldMapper
{
    /**
     * Never offered to the model: their state is not one attribute value.
     */
    private const UNSUPPORTED = [
        'Filament\Forms\Components\BaseFileUpload',
        'Filament\Forms\Components\Repeater',
        'Filament\Forms\Components\Builder',
        'Filament\Forms\Components\KeyValue',
        'Filament\Forms\Components\Hidden',
        'Filament\Forms\Components\ViewField',
        'Filament\Forms\Components\LivewireField',
        'Filament\Forms\Components\MorphToSelect',
        'Filament\Forms\Components\TableSelect',
        'Filament\Forms\Components\ModalTableSelect',
    ];

    private const MAX_OPTIONS = 100;

    public function map(Field $field): ?FieldBlueprint
    {
        foreach (self::UNSUPPORTED as $class) {
            if ($field instanceof $class) {
                return null;
            }
        }

        $name = $this->attempt(fn (): string => $field->getName(), '');

        if ($name === '' || str_contains($name, '.')) {
            return null;
        }

        $relationship = $this->attempt(fn (): bool => method_exists($field, 'hasRelationship') && $field->hasRelationship(), false);
        $multiple = $this->attempt(fn (): bool => method_exists($field, 'isMultiple') && $field->isMultiple(), false);

        // A multiple relationship select is saved by syncing a pivot table,
        // which filling one attribute cannot do.
        if ($relationship && $multiple) {
            return null;
        }

        [$type, $format] = $this->type($field, $multiple);

        return new FieldBlueprint(
            name: $name,
            label: $this->label($field, $name),
            type: $type,
            required: $this->attempt(fn (): bool => $field->isRequired(), false),
            rules: $this->rules($field),
            options: $relationship ? null : $this->options($field),
            format: $format,
            relationship: $relationship,
            relationshipName: $relationship ? $this->attempt(fn (): ?string => method_exists($field, 'getRelationshipName') ? $field->getRelationshipName() : null, null) : null,
            titleAttribute: $relationship ? $this->attempt(fn (): ?string => method_exists($field, 'getRelationshipTitleAttribute') ? $field->getRelationshipTitleAttribute() : null, null) : null,
            multiple: $multiple,
        );
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function type(Field $field, bool $multiple): array
    {
        $is = static fn (string $class): bool => $field instanceof $class;

        return match (true) {
            $is('Filament\Forms\Components\Toggle'), $is('Filament\Forms\Components\Checkbox') => [FieldBlueprint::BOOLEAN, null],
            $is('Filament\Forms\Components\CheckboxList'), $is('Filament\Forms\Components\TagsInput') => [FieldBlueprint::ARRAY, null],
            $is('Filament\Forms\Components\TimePicker') => [FieldBlueprint::STRING, 'time'],
            $is('Filament\Forms\Components\DatePicker') => [FieldBlueprint::STRING, 'date'],
            $is('Filament\Forms\Components\DateTimePicker') => [FieldBlueprint::STRING, 'date-time'],
            $is('Filament\Forms\Components\Slider') => [FieldBlueprint::NUMBER, null],
            $is('Filament\Forms\Components\TextInput') => match (true) {
                $this->attempt(fn (): bool => $field->isInteger(), false) => [FieldBlueprint::INTEGER, null],
                $this->attempt(fn (): bool => $field->isNumeric(), false) => [FieldBlueprint::NUMBER, null],
                $this->attempt(fn (): bool => $field->isEmail(), false) => [FieldBlueprint::STRING, 'email'],
                default => [FieldBlueprint::STRING, null],
            },
            $multiple => [FieldBlueprint::ARRAY, null],
            default => [FieldBlueprint::STRING, null],
        };
    }

    /**
     * @return array<string, string>|null
     */
    private function options(Field $field): ?array
    {
        if (! method_exists($field, 'getOptions')) {
            return null;
        }

        $options = $this->attempt(fn (): array => $field->getOptions(), []);
        $flat = [];

        foreach ($options as $value => $label) {
            // Grouped options: label => [value => label].
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

    /**
     * @return array<int, mixed>
     */
    private function rules(Field $field): array
    {
        $rules = $this->attempt(fn (): array => $field->getValidationRules(), []);

        return array_values(array_filter($rules, static fn (mixed $rule): bool => $rule !== null && $rule !== ''));
    }

    private function label(Field $field, string $name): string
    {
        $label = $this->attempt(fn (): string|Htmlable|null => $field->getLabel(), null);

        return $label === null || $label === '' ? Str::headline($name) : $this->plain($label);
    }

    private function plain(mixed $value): string
    {
        return $value instanceof Htmlable ? trim(strip_tags($value->toHtml())) : (string) $value;
    }

    /**
     * Fields are configured against a Livewire component that is not mounted
     * here, so any accessor may throw on a closure that expects one.
     *
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
