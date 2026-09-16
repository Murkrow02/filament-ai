<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking\Normalizers;

use Murkrow\FilamentAi\Contracts\TextNormalizer;
use Throwable;

/**
 * Applies a configured list of normalizers in order.
 *
 * Order matters: whitespace collapsing must come after de-hyphenation, which
 * needs the original line breaks.
 */
final class NormalizerPipeline implements TextNormalizer
{
    /** @var array<int, TextNormalizer> */
    private array $normalizers;

    /**
     * @param  array<int, TextNormalizer>  $normalizers
     */
    public function __construct(array $normalizers = [])
    {
        $this->normalizers = $normalizers;
    }

    /**
     * Resolves through the container when one is available, so a host can bind
     * a configured normalizer, and falls back to plain construction so the
     * chunker keeps working outside a booted application.
     *
     * @param  array<int, class-string<TextNormalizer>>  $classes
     */
    public static function fromClasses(array $classes): self
    {
        return new self(array_map(
            static function (string $class): TextNormalizer {
                try {
                    /** @var TextNormalizer */
                    return app($class);
                } catch (Throwable) {
                    return new $class;
                }
            },
            $classes,
        ));
    }

    public function normalize(string $text): string
    {
        foreach ($this->normalizers as $normalizer) {
            $text = $normalizer->normalize($text);
        }

        return $text;
    }
}
