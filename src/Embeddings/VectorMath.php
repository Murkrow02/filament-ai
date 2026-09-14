<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Embeddings;

/**
 * Small, allocation-conscious vector helpers.
 *
 * Vectors are L2-normalised on write, which makes cosine similarity identical
 * to the dot product. That matters twice over: pgvector's `<=>` operator can
 * then be read directly as `1 - similarity`, and MMR re-ranking -- which
 * compares candidates against each other in PHP -- becomes a plain dot product
 * instead of three passes over each vector.
 */
final class VectorMath
{
    /**
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    public static function normalize(array $vector): array
    {
        $norm = 0.0;

        foreach ($vector as $value) {
            $norm += $value * $value;
        }

        if ($norm <= 0.0) {
            return $vector;
        }

        $norm = sqrt($norm);

        foreach ($vector as $i => $value) {
            $vector[$i] = $value / $norm;
        }

        return $vector;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * Cosine similarity for vectors that may not be normalised.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Render a vector in the literal syntax pgvector accepts.
     *
     * @param  array<int, float>  $vector
     */
    public static function toPgLiteral(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn (float $v): string => rtrim(rtrim(sprintf('%.8F', $v), '0'), '.') ?: '0',
            $vector,
        )).']';
    }

    /**
     * @return array<int, float>
     */
    public static function fromPgLiteral(string $literal): array
    {
        $trimmed = trim($literal, "[] \t\n\r");

        if ($trimmed === '') {
            return [];
        }

        return array_map(floatval(...), explode(',', $trimmed));
    }
}
