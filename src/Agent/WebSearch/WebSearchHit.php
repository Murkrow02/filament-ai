<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

/**
 * One organic result: enough to judge relevance and to fetch the page for
 * more, without paying for the page itself up front.
 */
final readonly class WebSearchHit
{
    public function __construct(
        public string $title,
        public string $url,
        public string $snippet = '',
        public ?string $displayUrl = null,
    ) {}
}
