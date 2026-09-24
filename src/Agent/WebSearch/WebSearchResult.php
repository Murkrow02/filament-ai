<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

/**
 * What a search turned up, or why it did not.
 *
 * `failed` is about the search itself (no key, rate limited, provider down),
 * not about there being no results: a query that legitimately matches
 * nothing is a normal, successful, empty result.
 */
final readonly class WebSearchResult
{
    /**
     * @param  list<WebSearchHit>  $hits
     */
    public function __construct(
        public array $hits = [],
        public bool $failed = false,
        public ?string $error = null,
    ) {}

    public static function failure(string $error): self
    {
        return new self(failed: true, error: $error);
    }

    /**
     * Cached as plain arrays: a cache store that only unserializes allowed
     * classes would otherwise hand back an incomplete object and never hit.
     *
     * @return array{hits: list<array{title: string, url: string, snippet: string, display_url: ?string}>, failed: bool, error: ?string}
     */
    public function toArray(): array
    {
        return [
            'hits' => array_map(static fn (WebSearchHit $hit): array => [
                'title' => $hit->title,
                'url' => $hit->url,
                'snippet' => $hit->snippet,
                'display_url' => $hit->displayUrl,
            ], $this->hits),
            'failed' => $this->failed,
            'error' => $this->error,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hits: array_values(array_map(static fn (array $hit): WebSearchHit => new WebSearchHit(
                title: (string) ($hit['title'] ?? ''),
                url: (string) ($hit['url'] ?? ''),
                snippet: (string) ($hit['snippet'] ?? ''),
                displayUrl: isset($hit['display_url']) ? (string) $hit['display_url'] : null,
            ), (array) ($data['hits'] ?? []))),
            failed: (bool) ($data['failed'] ?? false),
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
    }

    /**
     * The single string the tool hands back to the model, numbered for
     * reference. Titles and snippets are written by the pages' authors, so the
     * list is marked as untrusted content.
     */
    public function toToolOutput(): string
    {
        if ($this->failed) {
            return 'Error: '.($this->error ?? 'the web search could not run.');
        }

        if ($this->hits === []) {
            return 'No results.';
        }

        $lines = [];

        foreach ($this->hits as $index => $hit) {
            $n = $index + 1;
            $lines[] = "[{$n}] {$hit->title}\n{$hit->url}\n".($hit->snippet === '' ? '(no snippet)' : $hit->snippet);
        }

        return "<untrusted_web_content>\n".implode("\n\n", $lines)."\n</untrusted_web_content>";
    }
}
