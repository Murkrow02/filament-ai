<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking\Normalizers;

use Murkrow\FilamentAi\Contracts\TextNormalizer;

/**
 * Expands typographic ligatures and normalises exotic whitespace and quotes.
 *
 * OCR engines faithfully reproduce the ligatures found in older print, which
 * then tokenise badly and defeat exact-match lookups: "ﬁrenze" and "firenze"
 * are different strings to both the tokenizer and a lexical index.
 */
final class FixOcrLigatures implements TextNormalizer
{
    /** @var array<string, string> */
    private const LIGATURES = [
        "\u{FB00}" => 'ff',
        "\u{FB01}" => 'fi',
        "\u{FB02}" => 'fl',
        "\u{FB03}" => 'ffi',
        "\u{FB04}" => 'ffl',
        "\u{FB05}" => 'st',
        "\u{FB06}" => 'st',
        "\u{0153}" => 'oe',
        "\u{0152}" => 'OE',
        "\u{00E6}" => 'ae',
        "\u{00C6}" => 'AE',
    ];

    /** @var array<string, string> */
    private const WHITESPACE = [
        "\u{00A0}" => ' ',  // no-break space
        "\u{2007}" => ' ',  // figure space
        "\u{202F}" => ' ',  // narrow no-break space
        "\u{2009}" => ' ',  // thin space
        "\u{3000}" => ' ',  // ideographic space
    ];

    /**
     * Dashes and quotes are folded to ASCII so that sentence splitting and
     * de-hyphenation only have to reason about one shape of each.
     *
     * @var array<string, string>
     */
    private const PUNCTUATION = [
        "\u{2010}" => '-',
        "\u{2011}" => '-',
        "\u{2012}" => '-',
        "\u{2013}" => '-',
        "\u{2014}" => '-',
        "\u{2212}" => '-',
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{201A}" => "'",
        "\u{201B}" => "'",
        "\u{2032}" => "'",
    ];

    public function normalize(string $text): string
    {
        return strtr($text, self::LIGATURES + self::WHITESPACE + self::PUNCTUATION);
    }
}
