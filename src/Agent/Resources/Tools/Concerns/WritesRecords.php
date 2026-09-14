<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\FieldBlueprint;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;

/**
 * What the create and edit tools share: argument schemas built from the form,
 * validation with the form's own rules, and the human summary shown when the
 * user is asked to approve.
 *
 * @property-read ResourceBlueprint $blueprint
 */
trait WritesRecords
{
    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    protected function fieldSchemas(JsonSchema $schema, bool $honourRequired): array
    {
        $fields = [];

        foreach ($this->blueprint->fields as $field) {
            $type = match ($field->type) {
                FieldBlueprint::BOOLEAN => $schema->boolean(),
                FieldBlueprint::INTEGER => $schema->integer(),
                FieldBlueprint::NUMBER => $schema->number(),
                FieldBlueprint::ARRAY => $schema->array()->items(
                    $field->options === null ? $schema->string() : $schema->string()->enum(array_map(strval(...), array_keys($field->options))),
                ),
                default => $field->options === null ? $schema->string() : $schema->string()->enum(array_map(strval(...), array_keys($field->options))),
            };

            $type->description($this->fieldDescription($field));

            if ($honourRequired && $field->required) {
                $type->required();
            }

            $fields[$field->name] = $type;
        }

        return $fields;
    }

    /**
     * The submitted values of known fields only; anything else the model sent
     * is dropped, so an argument cannot reach an attribute the form does not
     * expose.
     *
     * @return array<string, mixed>
     */
    protected function fieldValues(Request $request): array
    {
        $arguments = $request->all();
        $values = [];

        foreach ($this->blueprint->fields as $field) {
            if (array_key_exists($field->name, $arguments)) {
                $values[$field->name] = $arguments[$field->name];
            }
        }

        return $values;
    }

    /**
     * Validates with the rules the form declares. On an edit only the fields
     * being changed are validated, so a partial update does not trip the
     * `required` rules of fields it leaves alone.
     *
     * @param  array<string, mixed>  $values
     * @return string|null an error message the model can read, or null when valid
     */
    protected function validationError(array $values, bool $partial): ?string
    {
        $rules = [];
        $labels = [];

        foreach ($this->blueprint->fields as $field) {
            if ($partial && ! array_key_exists($field->name, $values)) {
                continue;
            }

            $rules[$field->name] = $field->rules;
            $labels[$field->name] = $field->label;
        }

        try {
            Validator::make($values, $rules, [], $labels)->validate();
        } catch (ValidationException $exception) {
            return 'Error: '.implode(' ', $exception->validator->errors()->all());
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function summarise(string $verb, array $values, ?string $subject = null): string
    {
        $parts = [];

        foreach ($this->blueprint->fields as $field) {
            if (! array_key_exists($field->name, $values)) {
                continue;
            }

            $value = $values[$field->name];
            $shown = match (true) {
                is_bool($value) => $value ? 'yes' : 'no',
                is_array($value) => implode(', ', array_map(strval(...), $value)),
                $value === null => 'empty',
                default => (string) ($field->options[(string) $value] ?? $value),
            };

            $parts[] = $field->label.': '.Str::limit($shown, 80);
        }

        $head = trim($verb.' '.$this->blueprint->label.($subject === null ? '' : ' '.$subject));

        return $parts === [] ? $head : $head.' -- '.implode('; ', $parts);
    }

    private function fieldDescription(FieldBlueprint $field): string
    {
        $description = $field->label;

        $description .= match ($field->format) {
            'date' => ' (date, YYYY-MM-DD)',
            'date-time' => ' (date and time, YYYY-MM-DD HH:MM:SS)',
            'time' => ' (time, HH:MM:SS)',
            'email' => ' (email address)',
            default => '',
        };

        if ($field->relationship) {
            $description .= ' (the id of the related record)';
        }

        if ($field->options !== null) {
            $choices = [];

            foreach ($field->options as $value => $label) {
                $choices[] = $value === $label ? (string) $value : "{$value} = {$label}";
            }

            $description .= '. One of: '.implode(', ', $choices);
        }

        return $description.'.';
    }
}
