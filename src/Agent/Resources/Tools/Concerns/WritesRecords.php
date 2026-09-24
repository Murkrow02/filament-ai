<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\FieldBlueprint;
use Murkrow\FilamentAi\Agent\Resources\FormOutcome;
use Murkrow\FilamentAi\Agent\Resources\FormPipeline;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Resources\ValueFormatter;

/**
 * What the create and edit tools share: argument schemas described from the
 * form, and the human summary shown when the user is asked to approve.
 *
 * Validation and dehydration are not here: `FormPipeline` runs the resource's
 * form, so a write is held to exactly what the panel's own pages enforce.
 *
 * A call the form would refuse is not put to the user at all -- approving a
 * change that cannot be saved is a question with no good answer. The refusal
 * is remembered for that call, so `handle()` answers with the form's errors
 * and never writes, even if the data changed in between.
 *
 * @property-read ResourceBlueprint $blueprint
 */
trait WritesRecords
{
    private ?string $refusedCall = null;

    /**
     * @return array<string, Type>
     */
    protected function fieldSchemas(JsonSchema $schema, string $ability, bool $honourRequired): array
    {
        $fields = [];

        foreach ($this->blueprint->fieldsFor($ability) as $field) {
            $enum = $field->options === null ? null : array_map(strval(...), array_keys($field->options));

            $type = match ($field->type) {
                FieldBlueprint::BOOLEAN => $schema->boolean(),
                FieldBlueprint::INTEGER => $schema->integer(),
                FieldBlueprint::NUMBER => $schema->number(),
                FieldBlueprint::ARRAY => $schema->array()->items($enum === null ? $schema->string() : $schema->string()->enum($enum)),
                default => $enum === null ? $schema->string() : $schema->string()->enum($enum),
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
     * The submitted arguments, minus the record id. Which of them the form
     * takes is decided by the form.
     *
     * @return array<string, mixed>
     */
    protected function submittedValues(Request $request): array
    {
        $values = $request->all();
        unset($values['id']);

        return $values;
    }

    protected function forms(): FormPipeline
    {
        return app(FormPipeline::class);
    }

    protected function refuse(Request $request): void
    {
        $this->refusedCall = $this->fingerprint($request);
    }

    protected function wasRefused(Request $request): bool
    {
        return $this->refusedCall !== null && $this->refusedCall === $this->fingerprint($request);
    }

    /**
     * A line a person can approve: the action, the record, and each change
     * with its label and readable values.
     */
    protected function summarise(string $action, FormOutcome $outcome, ?string $title = null): string
    {
        $head = (string) __('filament-ai::messages.approval.'.$action, [
            'label' => $this->blueprint->label,
            'title' => $title ?? '',
        ]);

        $parts = [];

        foreach ($outcome->changes as $name => $change) {
            $field = $this->blueprint->field($name);
            $label = $field->label ?? $name;
            $model = $this->blueprint->resource::getModel();
            $after = ValueFormatter::format($field, $change['after'], $model, 80);

            $parts[] = $action === 'edit'
                ? $label.': '.ValueFormatter::format($field, $change['before'], $model, 80).' → '.$after
                : $label.': '.$after;
        }

        return $parts === [] ? $head : $head.' — '.implode('; ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ignoredNote(FormOutcome $outcome): array
    {
        return $outcome->ignored === [] ? [] : [
            'not_saved' => $outcome->ignored,
            'note' => 'These arguments were not saved: the form does not accept them for this user and record.',
        ];
    }

    private function fingerprint(Request $request): string
    {
        return hash('xxh128', $this->name().'|'.json_encode($request->all()));
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
