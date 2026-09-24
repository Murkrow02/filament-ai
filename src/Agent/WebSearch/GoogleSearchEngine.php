<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Murkrow\FilamentAi\Contracts\WebSearchEngine;
use Throwable;

/**
 * Google Programmable Search Engine (Custom Search JSON API).
 *
 * Every query is billed, so a query the agent already asked in the last
 * `cache_ttl` seconds is answered from cache instead of the network -- the
 * cheapest way to keep a search "efficient" is not to run it twice. Google's
 * own `num` cap is 10 results per request, enforced here regardless of what
 * the host configures.
 */
final readonly class GoogleSearchEngine implements WebSearchEngine
{
    public function __construct(
        private string $apiKey,
        private string $cx,
        private string $endpoint,
        private int $timeoutSeconds,
        private int $cacheTtlSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('filament-ai.agent.web_search.google.api_key', ''),
            cx: (string) config('filament-ai.agent.web_search.google.cx', ''),
            endpoint: (string) config('filament-ai.agent.web_search.google.endpoint', 'https://www.googleapis.com/customsearch/v1'),
            timeoutSeconds: (int) config('filament-ai.agent.web_search.google.timeout', 8),
            cacheTtlSeconds: (int) config('filament-ai.agent.web_search.cache_ttl', 3600),
        );
    }

    public function search(string $query, int $limit): WebSearchResult
    {
        if (trim($query) === '') {
            return WebSearchResult::failure('the "query" argument is required.');
        }

        if ($this->apiKey === '' || $this->cx === '') {
            return WebSearchResult::failure('web search is not configured (missing Google API key or search engine id).');
        }

        $limit = max(1, min(10, $limit));

        $cacheKey = 'filament-ai:web-search:google:'.md5($this->cx.'|'.$query.'|'.$limit);

        if ($this->cacheTtlSeconds > 0) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return WebSearchResult::fromArray($cached);
            }
        }

        Log::info('filament-ai: the assistant searched the web', ['query' => $query, 'user' => auth()->id()]);

        try {
            // The key travels as a header, not in the query string: URLs end
            // up in access logs, exception messages and request monitors.
            $response = Http::timeout($this->timeoutSeconds)->withHeaders(['X-Goog-Api-Key' => $this->apiKey])->get($this->endpoint, [
                'cx' => $this->cx,
                'q' => $query,
                'num' => $limit,
                'safe' => 'active',
            ]);
        } catch (Throwable $exception) {
            Log::warning('filament-ai: web search request failed', ['message' => $exception->getMessage()]);

            return WebSearchResult::failure('the search provider could not be reached.');
        }

        if ($response->failed()) {
            $reason = (string) ($response->json('error.message') ?? $response->status());

            Log::warning('filament-ai: web search returned an error', ['status' => $response->status(), 'reason' => $reason]);

            return WebSearchResult::failure("the search provider returned an error ({$reason}).");
        }

        $items = (array) $response->json('items', []);

        $hits = array_values(array_map(
            static fn (array $item): WebSearchHit => new WebSearchHit(
                title: (string) ($item['title'] ?? ''),
                url: (string) ($item['link'] ?? ''),
                snippet: (string) ($item['snippet'] ?? ''),
                displayUrl: isset($item['displayLink']) ? (string) $item['displayLink'] : null,
            ),
            $items,
        ));

        $result = new WebSearchResult($hits);

        if ($this->cacheTtlSeconds > 0) {
            Cache::put($cacheKey, $result->toArray(), $this->cacheTtlSeconds);
        }

        return $result;
    }
}
