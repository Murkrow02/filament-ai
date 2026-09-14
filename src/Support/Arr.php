<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Support;

use Illuminate\Support\Arr as IlluminateArr;

final class Arr
{
    /**
     * Recursively merge $overrides over $base, treating list arrays as
     * replacements rather than concatenations.
     *
     * Used to layer per-source chunking overrides on top of the global block
     * without turning `normalizers` into a duplicated list.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function mergeConfig(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null)) {
                $base[$key] = self::mergeConfig($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Only keep the given keys, in the given order, dropping missing ones.
     *
     * @param  array<string, mixed>  $array
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public static function pickExisting(array $array, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (IlluminateArr::has($array, $key)) {
                $result[$key] = IlluminateArr::get($array, $key);
            }
        }

        return $result;
    }
}
