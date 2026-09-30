<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Murkrow\FilamentAi\Contracts\WebSearchEngine;
use Throwable;

/**
 * Google results through Serper (serper.dev).
 *
 * Google's own Custom Search JSON API takes no new customers and shuts down
 * on 1 January 2027; Serper returns the same Google results pages. `gl` and
 * `hl` pin the country and language of those results, which matters for local
 * questions: an unpinned query about a small Italian town ranks differently
 * from one asked from Italy. Cached like the Google driver, since every query
 * is billed.
 */
final readonly class SerperSearchEngine implements WebSearchEngine
{
    public function __construct(
        private string $apiKey,
        private string $endpoint,
        private ?string $country,
        private ?string $language,
        private int $timeoutSeconds,
        private int $cacheTtlSeconds,
    ) {}

    public static function fromConfig(): self
    {
        $country = config('filament-ai.agent.web_search.serper.gl');
        $language = config('filament-ai.agent.web_search.serper.hl');

        return new self(
            apiKey: (string) config('filament-ai.agent.web_search.serper.api_key', ''),
            endpoint: (string) config('filament-ai.agent.web_search.serper.endpoint', 'https://google.serper.dev/search'),
            country: blank($country) ? null : (string) $country,
            language: blank($language) ? null : (string) $language,
            timeoutSeconds: (int) config('filament-ai.agent.web_search.serper.timeout', 8),
            cacheTtlSeconds: (int) config('filament-ai.agent.web_search.cache_ttl', 3600),
        );
    }

    public function search(string $query, int $limit): WebSearchResult
    {
        if (trim($query) === '') {
            return WebSearchResult::failure('the "query" argument is required.');
        }

        if ($this->apiKey === '') {
            return WebSearchResult::failure('web search is not configured (missing Serper API key).');
        }

        $limit = max(1, min(10, $limit));

        $payload = array_filter([
            'q' => $query,
            'num' => $limit,
            'gl' => $this->country,
            'hl' => $this->language,
        ], static fn (mixed $value): bool => $value !== null);

        $cacheKey = 'filament-ai:web-search:serper:'.md5(json_encode($payload) ?: $query);

        if ($this->cacheTtlSeconds > 0) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return WebSearchResult::fromArray($cached);
            }
        }

        Log::info('filament-ai: the assistant searched the web', ['query' => $query, 'user' => auth()->id()]);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['X-API-KEY' => $this->apiKey])
                ->acceptJson()
                ->post($this->endpoint, $payload);
        } catch (Throwable $exception) {
            Log::warning('filament-ai: web search request failed', ['message' => $exception->getMessage()]);

            return WebSearchResult::failure('the search provider could not be reached.');
        }

        if ($response->failed()) {
            $reason = (string) ($response->json('message') ?? $response->status());

            Log::warning('filament-ai: web search returned an error', ['status' => $response->status(), 'reason' => $reason]);

            return WebSearchResult::failure("the search provider returned an error ({$reason}).");
        }

        $hits = [];

        // Google's answer box, when there is one, is usually the answer
        // itself; it goes first, as the page it was taken from.
        $answer = (array) $response->json('answerBox', []);

        if (isset($answer['link']) && (isset($answer['snippet']) || isset($answer['answer']))) {
            $hits[] = new WebSearchHit(
                title: (string) ($answer['title'] ?? $answer['link']),
                url: (string) $answer['link'],
                snippet: (string) ($answer['answer'] ?? $answer['snippet']),
                displayUrl: parse_url((string) $answer['link'], PHP_URL_HOST) ?: null,
            );
        }

        foreach ((array) $response->json('organic', []) as $item) {
            if (! is_array($item) || ! isset($item['link']) || count($hits) >= $limit) {
                continue;
            }

            $hits[] = new WebSearchHit(
                title: (string) ($item['title'] ?? ''),
                url: (string) $item['link'],
                snippet: (string) ($item['snippet'] ?? ''),
                displayUrl: parse_url((string) $item['link'], PHP_URL_HOST) ?: null,
            );
        }

        $result = new WebSearchResult(array_values($hits));

        if ($this->cacheTtlSeconds > 0) {
            Cache::put($cacheKey, $result->toArray(), $this->cacheTtlSeconds);
        }

        return $result;
    }
}
