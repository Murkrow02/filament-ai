<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Tools\FetchWebPage;
use Murkrow\FilamentAi\Agent\Tools\WebSearch;
use Murkrow\FilamentAi\Agent\WebSearch\FakeSearchEngine;
use Murkrow\FilamentAi\Agent\WebSearch\GoogleSearchEngine;
use Murkrow\FilamentAi\Agent\WebSearch\WebSearchHit;
use Murkrow\FilamentAi\Agent\WebSearch\WebSearchResult;
use Murkrow\FilamentAi\Contracts\WebSearchEngine;

beforeEach(function (): void {
    config()->set('filament-ai.agent.web_search.enabled', true);
    config()->set('filament-ai.agent.web_search.google.api_key', 'test-key');
    config()->set('filament-ai.agent.web_search.google.cx', 'engine-1');
});

it('is offered only when it can answer', function (): void {
    expect(WebSearch::enabled())->toBeTrue();

    config()->set('filament-ai.agent.web_search.google.cx', null);

    expect(WebSearch::enabled())->toBeFalse();
});

it('keeps page fetching off until it is switched on separately', function (): void {
    expect(FetchWebPage::enabled())->toBeFalse();

    config()->set('filament-ai.agent.web_search.fetch_page.enabled', true);

    expect(FetchWebPage::enabled())->toBeTrue();
});

it('searches through the bound engine and bounds the limit', function (): void {
    $fake = new FakeSearchEngine([new WebSearchResult([new WebSearchHit('Title', 'https://example.com', 'Snippet')])]);
    app()->instance(WebSearchEngine::class, $fake);

    $output = (new WebSearch)->handle(new Request(['query' => 'filament', 'limit' => 5000]));

    expect($fake->calls)->toBe([['query' => 'filament', 'limit' => 10]])
        ->and($output)->toContain('[1] Title')->toStartWith('<untrusted_web_content>');
});

it('sends the Google key as a header and caches by engine', function (): void {
    Http::fake(['*' => Http::response(['items' => [['title' => 'A', 'link' => 'https://a.example', 'snippet' => 's']]])]);

    $engine = GoogleSearchEngine::fromConfig();

    $engine->search('laravel', 3);
    $engine->search('laravel', 3);

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('X-Goog-Api-Key', 'test-key')
        && ! str_contains($request->url(), 'test-key'));

    config()->set('filament-ai.agent.web_search.google.cx', 'engine-2');
    GoogleSearchEngine::fromConfig()->search('laravel', 3);

    Http::assertSentCount(2);
    expect(Cache::get('filament-ai:web-search:google:'.md5('engine-1|laravel|3')))->toBeArray();
});

it('refuses to fetch an address inside this network, redirects included', function (): void {
    config()->set('filament-ai.agent.web_search.fetch_page.enabled', true);

    Http::fake([
        'http://93.184.215.14/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '*' => Http::response('secret'),
    ]);

    expect((new FetchWebPage)->handle(new Request(['url' => 'http://127.0.0.1:6379/'])))->toBe('Error: this url cannot be fetched.')
        ->and((new FetchWebPage)->handle(new Request(['url' => 'http://93.184.215.14/start'])))->toBe('Error: this url cannot be fetched.');

    Http::assertSentCount(1);
});

it('returns readable, capped, valid text marked as untrusted', function (): void {
    config()->set('filament-ai.agent.web_search.fetch_page.enabled', true);
    config()->set('filament-ai.agent.web_search.fetch_page.max_output_characters', 500);

    Http::fake(['*' => Http::response(
        '<html><script>steal()</script><p>Caf'."\xE9".' menu</p>'.str_repeat('<p>more text</p>', 400).'</html>',
        200,
        ['Content-Type' => 'text/html; charset=ISO-8859-1'],
    )]);

    $output = (new FetchWebPage)->handle(new Request(['url' => 'http://93.184.215.14/page']));

    expect($output)->toStartWith('<untrusted_web_content url="http://93.184.215.14/page">')
        ->toContain('Café menu')
        ->not->toContain('steal()')
        ->toContain('[... truncated ...]')
        ->and(json_encode($output))->not->toBeFalse();

    Http::assertSent(fn (HttpRequest $request): bool => true);
});
