<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Ingestion;

/**
 * Converts token counts into micro-USD integers.
 *
 * Integers rather than floats because these numbers are summed across millions
 * of rows in SQL, where float accumulation drifts. A run's cost is therefore
 * exact to the microdollar, and prices live in config so a provider price
 * change is not a code change.
 */
final class CostCalculator
{
    public static function embeddingMicros(string $model, int $tokens): int
    {
        $perMillion = config("rag.embeddings.pricing.{$model}");

        if ($perMillion === null) {
            return 0;
        }

        return (int) round(((float) $perMillion) * $tokens);
    }

    public static function completionMicros(string $model, int $promptTokens, int $completionTokens): int
    {
        /** @var array{input?: float|int, output?: float|int}|null $pricing */
        $pricing = config("rag.llm.pricing.{$model}");

        if ($pricing === null) {
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
}
