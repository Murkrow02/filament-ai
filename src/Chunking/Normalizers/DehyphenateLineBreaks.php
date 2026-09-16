<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking\Normalizers;

use Murkrow\FilamentAi\Contracts\TextNormalizer;

/**
 * Rejoins words split by a hyphen at end of line.
 *
 * Print sources hyphenate across line breaks constantly; left alone, "paro-\nla"
 * embeds as two meaningless fragments and never matches a query for "parola".
 *
 * The rejoin only fires when the character after the break is lowercase, so a
 * genuine compound followed by a capitalised proper noun is left intact.
 */
final class DehyphenateLineBreaks implements TextNormalizer
{
    private const HYPHEN_BREAK = '/(\p{Ll})-\s*\R\s*(\p{Ll})/u';

    public function normalize(string $text): string
    {
        return (string) preg_replace(self::HYPHEN_BREAK, '$1$2', $text);
    }
}
