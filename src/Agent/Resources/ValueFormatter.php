<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Turns a stored or submitted field value into what a person reads on an
 * approval card: the option's label instead of its key, the related record's
 * title instead of its id, "Yes" instead of "1", a date in the reader's
 * format.
 */
final class ValueFormatter
{
    /**
     * @param  class-string<Model>  $model
     */
    public static function format(?FieldBlueprint $field, mixed $value, string $model, int $limit = 300): string
    {
        if ($value === null || $value === '' || $value === []) {
            return (string) __('filament-ai::messages.approval.empty');
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof UnitEnum) {
            $value = $value->name;
        }

        if (is_array($value)) {
            return Str::limit(implode(', ', array_map(
                static fn (mixed $item): string => self::format($field, $item, $model, $limit),
                $value,
            )), $limit);
        }

        if ($field?->relationship && $field->relationshipName !== null && is_scalar($value)) {
            return self::related($field, $value, $model) ?? '#'.$value;
        }

        if ($field?->options !== null && is_scalar($value) && array_key_exists((string) $value, $field->options)) {
            return $field->options[(string) $value];
        }

        if (is_bool($value) || $field?->type === FieldBlueprint::BOOLEAN) {
            return (string) __('filament-ai::messages.approval.'.(filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no'));
        }

        if ($value instanceof DateTimeInterface || in_array($field?->format, ['date', 'date-time'], true)) {
            return self::date($value, $field?->format === 'date' ? 'date' : 'date-time') ?? (string) $value;
        }

        if ($value instanceof Htmlable) {
            $value = strip_tags($value->toHtml());
        }

        return Str::limit(is_scalar($value) || $value instanceof \Stringable ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE), $limit);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private static function related(FieldBlueprint $field, mixed $key, string $model): ?string
    {
        try {
            $relation = app($model)->{$field->relationshipName}();
            $related = $relation->getRelated();
            $title = $related->newQuery()->whereKey($key)->value($field->titleAttribute ?? $related->getKeyName());

            return $title === null ? null : (string) $title;
        } catch (Throwable) {
            return null;
        }
    }

    private static function date(mixed $value, string $format): ?string
    {
        try {
            $date = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }

        return $format === 'date' ? $date->isoFormat('L') : $date->isoFormat('L LT');
    }
}
