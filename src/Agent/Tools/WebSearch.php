<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Tools\Concerns\GuardsToolFailures;
use Murkrow\FilamentAi\Contracts\WebSearchEngine;

/**
 * Lets the agent search the open web, for anything the indexed knowledge base
 * does not cover: current events, a fact outside the corpus, a page to link.
 *
 * Deliberately snippet-only. Pulling every result's full page would burn
 * tokens and requests on the nine the model never reads; it searches first,
 * then reads fetch_web_page only for the one or two results worth reading in
 * full. That is what makes this "efficient" rather than just "works".
 */
final class WebSearch implements Tool
{
    use GuardsToolFailures;

    public function name(): string
    {
        return 'search_web';
    }

    public function description(): string
    {
        return 'Search the public web via Google and get back titles, urls and snippets. '
            .'Use it for anything current or outside the indexed knowledge base. '
            .'Read the snippets first; call fetch_web_page only on the specific result you need the full text of. '
            .'Prefer a few precise queries over one broad one.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query, as you would type it into a search engine.')
                ->required(),
            'limit' => $schema->integer()
                ->description('How many results to return, at most 10. Defaults to a small number -- ask for more only if the first pass was not enough.'),
        ];
    }

    public function handle(Request $request): string
    {
        if (! self::enabled()) {
            return 'Error: web search is switched off for this application.';
        }

        return $this->guarded(function () use ($request): string {
            $arguments = $request->all();
            $query = is_scalar($arguments['query'] ?? null) ? mb_substr(trim((string) $arguments['query']), 0, 400) : '';
            $limit = is_numeric($arguments['limit'] ?? null)
                ? (int) $arguments['limit']
                : (int) config('filament-ai.agent.web_search.default_results', 5);

            // Bound whatever the driver is: a custom one should not have to
            // defend itself against "limit: 5000".
            return app(WebSearchEngine::class)->search($query, max(1, min(10, $limit)))->toToolOutput();
        });
    }

    /**
     * On, and able to answer: a Google driver without its key or engine id
     * would only ever say so, one billed turn at a time.
     */
    public static function enabled(): bool
    {
        if (! config('filament-ai.enabled', true) || ! config('filament-ai.agent.enabled', true) || ! config('filament-ai.agent.web_search.enabled', false)) {
            return false;
        }

        return config('filament-ai.agent.web_search.driver', 'google') !== 'google'
            || (filled(config('filament-ai.agent.web_search.google.api_key')) && filled(config('filament-ai.agent.web_search.google.cx')));
    }
}
