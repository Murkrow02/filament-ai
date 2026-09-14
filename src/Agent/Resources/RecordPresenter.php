<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Turns a record into the plain array a tool result carries.
 *
 * Only the attributes the blueprint names are read, and an attribute the model
 * hides (`$hidden`, e.g. a password hash) is skipped even when named: the
 * resource declaring a column is not consent to hand it to a language model.
 */
final class RecordPresenter
{
    /**
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    public static function present(Model $record, ResourceBlueprint $blueprint, array $attributes, bool $withUrl = true): array
    {
        $resource = $blueprint->resource;
        $hidden = $record->getHidden();

        $data = [
            'id' => $record->getKey(),
            'title' => self::title($resource::getRecordTitle($record)),
        ];

        foreach ($attributes as $attribute) {
            if (in_array(explode('.', $attribute)[0], $hidden, true) || array_key_exists($attribute, $data)) {
                continue;
            }

            $data[$attribute] = self::scalar(data_get($record, $attribute));
        }

        if ($withUrl && ($url = self::url($resource, $record)) !== null) {
            $data['url'] = $url;
        }

        return $data;
    }

    /**
     * The resource's title for a record, as plain text.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function titleFor(string $resource, Model $record): ?string
    {
        return self::title($resource::getRecordTitle($record));
    }

    private static function title(string|Htmlable|null $title): ?string
    {
        if ($title instanceof Htmlable) {
            return trim(strip_tags($title->toHtml()));
        }

        return $title;
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private static function url(string $resource, Model $record): ?string
    {
        foreach (['view', 'edit'] as $page) {
            if (! $resource::hasPage($page)) {
                continue;
            }

            try {
                return $resource::getUrl($page, ['record' => $record]);
            } catch (Throwable) {
                // No panel routes in this context (a queue worker, a console
                // command): the record is still useful without a link.
                return null;
            }
        }

        return null;
    }

    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Model => $value->getKey(),
            $value instanceof Collection => $value->map(self::scalar(...))->values()->all(),
            is_array($value) => array_map(self::scalar(...), $value),
            $value instanceof Stringable => (string) $value,
            default => null,
        };
    }
}
