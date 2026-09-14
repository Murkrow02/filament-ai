<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources\Filters;

/**
 * Coercions shared by the filter implementations.
 *
 * Values reach a filter from three places -- a CLI string, a Filament form
 * array, an application call -- so every filter has to accept all three
 * shapes for the same concept.
 */
final class FilterValue
{
    public static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * "1,2,3" or [1, 2, 3] -> ['1', '2', '3'].
     *
     * @return array<int, string>
     */
    public static function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(strval(...), $value));
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', (string) $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Accepts "10-50", "10..50", [10, 50] and ['from' => 10, 'to' => 50].
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function bounds(mixed $value): array
    {
        if (is_array($value)) {
            $from = $value['from'] ?? $value[0] ?? null;
            $to = $value['to'] ?? $value[1] ?? null;

            return [
                self::isBlank($from) ? null : (string) $from,
                self::isBlank($to) ? null : (string) $to,
            ];
        }

        $string = (string) $value;
        $separator = str_contains($string, '..') ? '..' : '-';

        $parts = explode($separator, $string, 2);

        $from = trim($parts[0]);
        $to = trim($parts[1] ?? '');

        return [$from === '' ? null : $from, $to === '' ? null : $to];
    }

    public static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
