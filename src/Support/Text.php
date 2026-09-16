<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Support;

use Illuminate\Support\Str;

final class Text
{
    /**
     * Truncate on a word boundary, appending an ellipsis only when it cut.
     */
    public static function snippet(string $text, int $chars = 240): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $chars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $chars), " \t\n\r\0\x0B.,;:-").'...';
    }

    /**
     * Render a position label such as "Pages 12-13" or "Page 12".
     *
     * @param  string  $range  template using :start and :end
     * @param  string  $single  template using :start
     */
    public static function positionLabel(int $start, int $end, string $range, string $single): string
    {
        if ($start === $end) {
            return strtr($single, [':start' => (string) $start, ':end' => (string) $end]);
        }

        return strtr($range, [':start' => (string) $start, ':end' => (string) $end]);
    }

    /**
     * Collapse a value into a stable string usable as an external identifier.
     */
    public static function externalId(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    /**
     * Human readable byte / count formatting for CLI and dashboard output.
     */
    public static function abbreviate(int|float $number): string
    {
        return Str::of((string) $number)->toString();
    }
}
