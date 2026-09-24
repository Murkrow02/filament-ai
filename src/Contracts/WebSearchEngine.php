<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Murkrow\FilamentAi\Agent\WebSearch\WebSearchResult;

/**
 * Answers a query with a handful of ranked web results.
 *
 * The contract says nothing about which search provider: the shipped driver
 * talks to Google's Programmable Search Engine, and a host can bind its own
 * (Bing, Brave, an internal crawler) by registering a driver on
 * WebSearchManager, or bind this contract directly -- the tool resolves the
 * contract, so a test can bind `FakeSearchEngine`. Whatever implements it does its own caching and rate
 * limiting -- the tool above it only shapes the result for the model.
 */
interface WebSearchEngine
{
    public function search(string $query, int $limit): WebSearchResult;
}
