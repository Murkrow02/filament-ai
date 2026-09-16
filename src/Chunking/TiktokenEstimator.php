<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking;

use Murkrow\FilamentAi\Contracts\TokenEstimator;
use Yethee\Tiktoken\EncoderProvider;

/**
 * Exact BPE token counting, used only when yethee/tiktoken is installed.
 *
 * Encoders are memoised per encoding because building one parses a multi-megabyte
 * vocabulary file.
 */
final class TiktokenEstimator implements TokenEstimator
{
    /** @var array<string, \Yethee\Tiktoken\Encoder> */
    private static array $encoders = [];

    public function __construct(
        private readonly string $encoding = 'cl100k_base',
    ) {}

    public static function isAvailable(): bool
    {
        return class_exists(EncoderProvider::class);
    }

    public function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        self::$encoders[$this->encoding] ??= (new EncoderProvider)->get($this->encoding);

        return count(self::$encoders[$this->encoding]->encode($text));
    }
}
