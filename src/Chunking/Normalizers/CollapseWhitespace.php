<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking\Normalizers;

use Murkrow\FilamentAi\Contracts\TextNormalizer;

/**
 * Folds every run of whitespace into a single space and trims the result.
 *
 * Must run last: the normalizers before it rely on line breaks still existing.
 */
final class CollapseWhitespace implements TextNormalizer
{
    public function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
