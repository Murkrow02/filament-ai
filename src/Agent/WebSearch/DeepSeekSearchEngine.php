<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Contracts\WebSearchEngine;
use Throwable;

/**
 * DeepSeek's Search API (`POST /v1/tools/search`), billed per successful
 * request whatever the number of results, empty searches free.
 *
 * Each result carries a `summary` written for models, longer than the
 * page's snippet: that is what the agent reads, cut to `snippet_characters`,
 * and the short snippet is the fallback. Queries are capped at 128
 * characters by the API, so a longer one is cut here instead of refused
 * there. Results come ranked by DeepSeek's `rerank_score`; cached like the
 * other drivers.
 */
final readonly class DeepSeekSearchEngine implements WebSearchEngine
{
    private const MAX_QUERY_CHARACTERS = 128;

    public function __construct(
        private string $apiKey,
        private string $endpoint,
        private int $timeoutSeconds,
        private int $snippetCharacters,
        private int $cacheTtlSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('filament-ai.agent.web_search.deepseek.api_key', ''),
            endpoint: (string) config('filament-ai.agent.web_search.deepseek.endpoint', 'https://api.deepseek.com/v1/tools/search'),
            timeoutSeconds: (int) config('filament-ai.agent.web_search.deepseek.timeout', 15),
            snippetCharacters: max(100, (int) config('filament-ai.agent.web_search.deepseek.snippet_characters', 600)),
            cacheTtlSeconds: (int) config('filament-ai.agent.web_search.cache_ttl', 3600),
        );
    }

    public function search(string $query, int $limit): WebSearchResult
    {
        $query = trim($query);

        if ($query === '') {
            return WebSearchResult::failure('the "query" argument is required.');
        }

        if ($this->apiKey === '') {
            return WebSearchResult::failure('web search is not configured (missing DeepSeek API key).');
        }

        $limit = max(1, min(10, $limit));
        $payload = ['query' => Str::limit($query, self::MAX_QUERY_CHARACTERS, ''), 'count' => $limit];
        $cacheKey = 'filament-ai:web-search:deepseek:'.md5(json_encode($payload) ?: $query);

        if ($this->cacheTtlSeconds > 0) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return WebSearchResult::fromArray($cached);
            }
        }

        Log::info('filament-ai: the assistant searched the web', ['query' => $query, 'user' => auth()->id(), 'driver' => 'deepseek']);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post($this->endpoint, $payload);
        } catch (Throwable $exception) {
            Log::warning('filament-ai: web search request failed', ['message' => $exception->getMessage()]);

            return WebSearchResult::failure('the search provider could not be reached.');
        }

        if ($response->failed()) {
            $reason = (string) ($response->json('error.message') ?? $response->status());

            Log::warning('filament-ai: web search returned an error', ['status' => $response->status(), 'reason' => $reason]);

            return WebSearchResult::failure("the search provider returned an error ({$response->status()}: {$reason}).");
        }

        $hits = [];

        foreach ((array) $response->json('results', []) as $item) {
            if (! is_array($item) || blank($item['url'] ?? null) || count($hits) >= $limit) {
                continue;
            }

            $url = (string) $item['url'];
            $text = trim((string) ($item['summary'] ?? '')) ?: trim((string) ($item['snippet'] ?? ''));

            $hits[] = new WebSearchHit(
                title: (string) ($item['title'] ?? $url),
                url: $url,
                snippet: Str::limit(preg_replace('/\s+/u', ' ', $text) ?? $text, $this->snippetCharacters),
                displayUrl: filled($item['site_name'] ?? null) ? (string) $item['site_name'] : (parse_url($url, PHP_URL_HOST) ?: null),
            );
        }

        $result = new WebSearchResult($hits);

        if ($this->cacheTtlSeconds > 0) {
            Cache::put($cacheKey, $result->toArray(), $this->cacheTtlSeconds);
        }

        return $result;
    }
}
