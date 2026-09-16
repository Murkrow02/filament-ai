<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

/**
 * Parses the CLI's repeatable `--filter=name:value` option.
 */
final class FilterInput
{
    /**
     * @param  array<int, string>  $options
     * @return array<string, string>
     */
    public static function parseCli(array $options): array
    {
        $values = [];

        foreach ($options as $option) {
            if (! str_contains($option, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $option, 2);
            $values[trim($name)] = trim($value);
        }

        return $values;
    }
}
