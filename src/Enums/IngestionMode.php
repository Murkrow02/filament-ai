<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Enums;

enum IngestionMode: string
{
    /** Re-chunk and re-embed everything the filters match. */
    case Full = 'full';

    /** Skip documents whose content and chunking parameters are unchanged. */
    case Incremental = 'incremental';

    /** Do not re-chunk; only embed chunks that have no vector yet. */
    case EmbeddingsOnly = 'embeddings_only';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full re-index',
            self::Incremental => 'Incremental',
            self::EmbeddingsOnly => 'Embeddings only',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
