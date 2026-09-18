<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

/**
 * Turns token counts into integer micro-dollars, using the price lists in
 * config.
 *
 * Providers answer with dated model ids -- `claude-haiku-4-5-20251001`,
 * `gpt-4o-mini-2024-07-18` -- while a price list is written with the name a
 * human uses. Matching only exactly therefore priced most real answers at
 * zero, silently: the tokens were counted and the money was not, and a zero
 * looks like an answer. The lookup now falls back to the name without its
 * date, then to the longest configured name the model starts with.
 */
final class CostCalculator
{
    public static function embeddingMicros(string $model, int $tokens): int
    {
        $perMillion = self::priceFor('filament-ai.embeddings.pricing', $model);

        if ($perMillion === null) {
            return 0;
        }

        return (int) round(((float) $perMillion) * $tokens);
    }

    public static function completionMicros(string $model, int $promptTokens, int $completionTokens): int
    {
        $pricing = self::priceFor('filament-ai.llm.pricing', $model);

        if (! is_array($pricing)) {
            return 0;
        }

        $input = (float) ($pricing['input'] ?? 0);
        $output = (float) ($pricing['output'] ?? 0);

        return (int) round($input * $promptTokens + $output * $completionTokens);
    }

    public static function format(int $micros, int $decimals = 2): string
    {
        return '$'.number_format($micros / 1_000_000, $decimals);
    }

    /**
     * The configured name for a model, or null when nothing matches.
     *
     * Longest prefix first, so `gpt-4o-mini-...` is never priced as `gpt-4o`.
     */
    public static function priceKeyFor(string $table, string $model): ?string
    {
        $prices = (array) config($table, []);

        if ($model === '' || $prices === []) {
            return null;
        }

        if (array_key_exists($model, $prices)) {
            return $model;
        }

        // Dated snapshots: -20251001 or -2024-07-18.
        $undated = (string) preg_replace('/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', $model);

        if ($undated !== $model && array_key_exists($undated, $prices)) {
            return $undated;
        }

        $keys = array_map(strval(...), array_keys($prices));

        usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($keys as $key) {
            if ($key !== '' && str_starts_with($model, $key)) {
                return $key;
            }
        }

        return null;
    }

    private static function priceFor(string $table, string $model): mixed
    {
        $key = self::priceKeyFor($table, $model);

        return $key === null ? null : config("{$table}.{$key}");
    }
}
