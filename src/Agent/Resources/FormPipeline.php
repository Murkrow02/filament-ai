<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Forms\Components\Field;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs the agent's writes through the resource's own form, the way the panel's
 * create and edit pages do.
 *
 * `Schema::getState()` is Filament's whole save pipeline: validation with every
 * rule the fields declare (a Select's `in` rule, a relationship select checked
 * against its own scoped query, `unique()` ignoring the record being edited),
 * hidden and disabled fields left out, `dehydrated(false)` dropped,
 * `dehydrateStateUsing()` applied, and fields
 * inside a `->relationship()` layout saved to the related model. Re-implementing
 * any of that would drift; running it keeps the agent exactly as capable as
 * the user is on the page, never more.
 *
 * The flow mirrors `CreateRecord::create()` and `EditRecord::save()`: fill the
 * form, overlay what was submitted, `getState()`, the resource's optional
 * `agentMutateBeforeCreate()` / `agentMutateBeforeSave()` hook (page hooks
 * live on page instances the agent never mounts), then `new Model($data)` +
 * `save()` or `update()` in a transaction.
 *
 * Password fields are never taken from the model, whatever the form says.
 */
final class FormPipeline
{
    public const CREATE = 'create';

    public const EDIT = 'edit';

    /**
     * The fields the model may be told about for an operation: plain
     * attributes at the root of the form that are visible and enabled before
     * anything is filled in. A field whose visibility cannot be evaluated is
     * left out -- failing closed is the point.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return list<Field>
     */
    public function offeredFields(string $resource, string $operation): array
    {
        try {
            [, $schema] = $this->schema($resource, $operation, $resource::getModel());
            $schema->fill();
            $fields = $this->rootFields($schema);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        // Not isDehydrated(): a field routinely dehydrates only once it has a
        // value (`dehydrated(fn ($state) => filled($state))`), and nothing is
        // filled in yet. Filament drops what it would not save at write time.
        return array_values(array_filter($fields, static function (Field $field): bool {
            try {
                return ! $field->isHidden() && ! $field->isDisabled();
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        }));
    }

    /**
     * Validate and dehydrate without saving anything, for the approval card:
     * what the write would store, or why it would be refused.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @param  array<string, mixed>  $values
     */
    public function preview(string $resource, ?Model $record, array $values): FormOutcome
    {
        return $this->run($resource, $record, $values, function (Schema $schema, array $accepted) use ($resource, $record): FormOutcome {
            $data = $schema->getState(shouldCallHooksBefore: false);

            return $record === null
                ? $this->outcome($data, $accepted, app($resource::getModel()), existing: false)
                : $this->outcome($data, $accepted, $record, existing: true);
        });
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @param  array<string, mixed>  $values
     */
    public function create(string $resource, array $values): FormOutcome
    {
        return $this->run($resource, null, $values, function (Schema $schema, array $accepted) use ($resource): FormOutcome {
            return DB::transaction(function () use ($schema, $accepted, $resource): FormOutcome {
                $data = $schema->getState();

                if (method_exists($resource, 'agentMutateBeforeCreate')) {
                    $data = $resource::agentMutateBeforeCreate($data);
                }

                $model = $resource::getModel();
                /** @var Model $record */
                $record = new $model($data);
                $record->save();

                $schema->model($record)->saveRelationships();

                return $this->outcome($data, $accepted, $record, existing: false, saved: $record);
            });
        });
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @param  array<string, mixed>  $values
     */
    public function update(string $resource, Model $record, array $values): FormOutcome
    {
        $before = clone $record;

        return $this->run($resource, $record, $values, function (Schema $schema, array $accepted) use ($resource, $record, $before): FormOutcome {
            return DB::transaction(function () use ($schema, $accepted, $resource, $record, $before): FormOutcome {
                $data = $schema->getState(afterValidate: static fn (): null => null);

                if (method_exists($resource, 'agentMutateBeforeSave')) {
                    $data = $resource::agentMutateBeforeSave($record, $data);
                }

                $outcome = $this->outcome($data, $accepted, $before, existing: true);

                $record->update($data);

                return new FormOutcome($outcome->data, [], $outcome->ignored, $outcome->changes, $record);
            });
        });
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @param  array<string, mixed>  $values
     * @param  callable(Schema, array<string, mixed>): FormOutcome  $then
     */
    private function run(string $resource, ?Model $record, array $values, callable $then): FormOutcome
    {
        try {
            [, $schema] = $this->schema($resource, $record === null ? self::CREATE : self::EDIT, $record ?? $resource::getModel());

            // What the pages do on mount: defaults for a new record, the
            // record's own attributes for an existing one.
            $record === null ? $schema->fill() : $schema->fill($record->attributesToArray());

            $roots = [];

            foreach ($this->rootFields($schema) as $field) {
                $roots[$field->getName()] = $field;
            }

            // Only plain attributes at the root of the form are taken from the
            // arguments. The rest the model never gets to address: what Filament
            // then drops (hidden, disabled, not dehydrated) is reported too.
            $accepted = array_intersect_key($values, $roots);
            $ignored = array_keys(array_diff_key($values, $roots));

            $livewire = $schema->getLivewire();

            foreach ($accepted as $name => $value) {
                data_set($livewire, 'data.'.$name, $value);
            }

            $outcome = $then($schema, $accepted);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors[preg_replace('/^data\./', '', (string) $key)] = array_values($messages);
            }

            return new FormOutcome(errors: $errors, ignored: $ignored ?? []);
        } catch (Halt) {
            return new FormOutcome(failure: 'the form refused this change.', ignored: $ignored ?? []);
        }

        return new FormOutcome(
            $outcome->data,
            $outcome->errors,
            array_values(array_unique([...($ignored ?? []), ...$outcome->ignored])),
            $outcome->changes,
            $outcome->record,
            $outcome->failure,
        );
    }

    /**
     * What the dehydrated state changes, and which submitted keys it left out.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $accepted
     * @param  Model  $target  the record being edited, or a blank instance for a creation
     */
    private function outcome(array $data, array $accepted, Model $target, bool $existing, ?Model $saved = null): FormOutcome
    {
        $ignored = [];
        $changes = [];

        foreach (array_keys($accepted) as $name) {
            // Not in the state: hidden, disabled or not dehydrated for this
            // user and this state. Not fillable: `new Model($data)` and
            // `update()` would drop it without a word.
            if (! array_key_exists($name, $data) || ! $target->isFillable($name)) {
                $ignored[] = $name;
            }
        }

        foreach ($data as $name => $after) {
            if (! is_string($name) || in_array($name, $ignored, true)) {
                continue;
            }

            $was = $existing ? $target->getAttribute($name) : null;

            $unchanged = $existing
                ? $this->same($was, $after)
                : ! array_key_exists($name, $accepted) && ($after === null || $after === '' || $after === []);

            if (! $unchanged) {
                $changes[$name] = ['before' => $was, 'after' => $after];
            }
        }

        return new FormOutcome($data, [], $ignored, $changes, $saved);
    }

    private function same(mixed $before, mixed $after): bool
    {
        if ($before instanceof \DateTimeInterface) {
            $before = $before->format('Y-m-d H:i:s');
            $after = is_string($after) && $after !== '' ? date('Y-m-d H:i:s', strtotime($after) ?: 0) : $after;
        }

        if ($before instanceof \BackedEnum) {
            $before = $before->value;
        }

        if ($after instanceof \BackedEnum) {
            $after = $after->value;
        }

        if (is_bool($before) || is_bool($after)) {
            return (bool) $before === (bool) $after;
        }

        if (is_array($before) || is_array($after)) {
            return $before == $after;
        }

        return (string) $before === (string) $after;
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return array{0: AgentFormHost, 1: Schema}
     */
    private function schema(string $resource, string $operation, Model|string $record): array
    {
        $host = AgentFormHost::for($resource, $operation, $record);
        $schema = $host->getSchema('form');

        if (! $schema instanceof Schema) {
            throw new \RuntimeException("The form of [{$resource}] could not be built.");
        }

        return [$host, $schema];
    }

    /**
     * @return list<Field>
     */
    private function rootFields(Schema $schema): array
    {
        $root = (string) $schema->getStatePath();
        $fields = [];

        foreach ($schema->getFlatFields(withHidden: true) as $field) {
            if (! $field instanceof Field) {
                continue;
            }

            $name = $field->getName();

            if ($name === '' || str_contains($name, '.') || $field->getStatePath() !== $root.'.'.$name) {
                continue;
            }

            // A secret typed to a language model is no longer a secret: it is
            // in the provider's logs and in the stored conversation.
            if (method_exists($field, 'isPassword') && $field->isPassword()) {
                continue;
            }

            $fields[] = $field;
        }

        return $fields;
    }
}
