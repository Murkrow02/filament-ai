<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * A document as the source sees it, before it exists in the package's tables.
 */
final readonly class DocumentDraft
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceKey,
        public string $externalId,
        public ?string $title = null,
        public array $metadata = [],
    ) {}
}
