<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

use Murkrow\FilamentAi\Contracts\WebSearchEngine;

/**
 * For tests: returns whatever was queued, records what was asked.
 */
final class FakeSearchEngine implements WebSearchEngine
{
    /** @var list<WebSearchResult> */
    private array $queue;

    /** @var list<array{query: string, limit: int}> */
    public array $calls = [];

    /**
     * @param  list<WebSearchResult>  $results
     */
    public function __construct(array $results = [])
    {
        $this->queue = $results;
    }

    public function search(string $query, int $limit): WebSearchResult
    {
        $this->calls[] = ['query' => $query, 'limit' => $limit];

        return array_shift($this->queue) ?? new WebSearchResult([]);
    }
}
