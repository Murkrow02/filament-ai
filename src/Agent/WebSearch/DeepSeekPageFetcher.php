<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads a page through DeepSeek's Fetch API (`POST /v1/tools/fetch`): their
 * servers load it -- pages built by JavaScript included -- and hand back the
 * content as Markdown. Nothing is requested from this server's network, so
 * none of `PublicUrlGuard`'s pinning is needed; the url is only checked to be
 * plain http(s).
 *
 * `fetch()` returns the page, or null with the reason in `$error` and the
 * HTTP status in `$status`: 403 (their policy refused it) and 404 (nothing
 * usable came back) are the cases `FetchWebPage` may retry directly.
 */
final class DeepSeekPageFetcher
{
    public ?string $error = null;

    public ?int $status = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint,
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('filament-ai.agent.web_search.deepseek.api_key', ''),
            endpoint: (string) config('filament-ai.agent.web_search.deepseek.fetch_endpoint', 'https://api.deepseek.com/v1/tools/fetch'),
            timeoutSeconds: (int) config('filament-ai.agent.web_search.deepseek.fetch_timeout', 30),
        );
    }

    /**
     * @return array{title: string, content: string}|null
     */
    public function fetch(string $url): ?array
    {
        $this->error = null;
        $this->status = null;

        if ($this->apiKey === '') {
            $this->error = 'fetching through DeepSeek is not configured (missing DeepSeek API key).';

            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || blank(parse_url($url, PHP_URL_HOST)) || filled(parse_url($url, PHP_URL_USER)) || strlen($url) > 8192) {
            $this->error = 'this url cannot be fetched.';

            return null;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post($this->endpoint, ['url' => $url]);
        } catch (Throwable) {
            $this->error = 'the page could not be reached.';

            return null;
        }

        if ($response->failed()) {
            $this->status = $response->status();
            $this->error = "the fetch service returned {$response->status()}".(filled($response->json('error.message')) ? ': '.$response->json('error.message') : '').'.';

            return null;
        }

        return [
            'title' => trim((string) $response->json('title', '')),
            'content' => mb_scrub((string) $response->json('content', ''), 'UTF-8'),
        ];
    }
}
