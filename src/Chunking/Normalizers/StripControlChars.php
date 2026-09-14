<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking\Normalizers;

use Murkrow\FilamentAi\Contracts\TextNormalizer;

/**
 * Removes characters that carry no meaning but do consume tokens and can break
 * downstream JSON encoding: C0/C1 controls (except tab and newline), zero-width
 * joiners, BOMs and the Unicode replacement character that OCR emits for
 * glyphs it could not decode.
 */
final class StripControlChars implements TextNormalizer
{
    private const CONTROL = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u';

    private const ZERO_WIDTH = '/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u';

    private const REPLACEMENT = '/\x{FFFD}/u';

    public function normalize(string $text): string
    {
        $text = (string) preg_replace(self::CONTROL, '', $text);
        $text = (string) preg_replace(self::ZERO_WIDTH, '', $text);

        return (string) preg_replace(self::REPLACEMENT, '', $text);
    }
}
