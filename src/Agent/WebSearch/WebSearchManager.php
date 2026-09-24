<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;
use Murkrow\FilamentAi\Contracts\WebSearchEngine;

/**
 * @method WebSearchEngine driver(string|null $driver = null)
 */
final class WebSearchManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('filament-ai.agent.web_search.driver', 'google');
    }

    public function createGoogleDriver(): WebSearchEngine
    {
        return GoogleSearchEngine::fromConfig();
    }

    public function createFakeDriver(): WebSearchEngine
    {
        return new FakeSearchEngine;
    }

    /**
     * Register a search provider of your own (Bing, Brave, an internal index).
     *
     * @param  Closure(Container): WebSearchEngine  $callback
     */
    public function register(string $name, Closure $callback): self
    {
        $this->extend($name, $callback);

        return $this;
    }
}
