<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

use Closure;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Exceptions\InvalidSourceConfigurationException;
use Murkrow\FilamentAi\Exceptions\UnknownSourceException;

/**
 * Every knowledge source the application exposes.
 *
 * Sources are classes, listed in `filament-ai.sources` and resolved through the
 * container so they can take constructor dependencies. Anything registered at
 * runtime -- a closure-built source, a test double -- wins over the configured
 * list under the same key.
 */
final class SourceRegistry
{
    /** @var array<string, KnowledgeSource> */
    private array $registered = [];

    /** @var array<string, Closure(): KnowledgeSource> */
    private array $factories = [];

    /** @var array<string, KnowledgeSource> */
    private array $configured = [];

    private bool $loaded = false;

    public function register(KnowledgeSource $source): void
    {
        $this->registered[$source->key()] = $source;
    }

    /**
     * Register a source built lazily, for one whose construction is expensive
     * or which must not run at boot.
     *
     * @param  Closure(): KnowledgeSource  $factory
     */
    public function extend(string $key, Closure $factory): void
    {
        $this->factories[$key] = $factory;
        unset($this->registered[$key]);
    }

    public function has(string $key): bool
    {
        $this->load();

        return isset($this->registered[$key])
            || isset($this->factories[$key])
            || isset($this->configured[$key]);
    }

    public function get(string $key): KnowledgeSource
    {
        $this->load();

        if (isset($this->registered[$key])) {
            return $this->registered[$key];
        }

        if (isset($this->factories[$key])) {
            return $this->registered[$key] = ($this->factories[$key])();
        }

        return $this->configured[$key] ?? throw UnknownSourceException::for($key, $this->keys());
    }

    /**
     * @return array<string, KnowledgeSource>
     */
    public function all(): array
    {
        $sources = [];

        foreach ($this->keys() as $key) {
            $sources[$key] = $this->get($key);
        }

        return $sources;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        $this->load();

        return array_values(array_unique([
            ...array_keys($this->configured),
            ...array_keys($this->registered),
            ...array_keys($this->factories),
        ]));
    }

    /**
     * Key => label, for select inputs and CLI listings.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $key => $source) {
            $options[$key] = $source->label();
        }

        return $options;
    }

    /**
     * The subset MCP is allowed to see.
     *
     * @return array<int, string>
     */
    public function exposedKeys(): array
    {
        $allowed = config('filament-ai.mcp.sources');

        if ($allowed === null) {
            return $this->keys();
        }

        return array_values(array_intersect($this->keys(), (array) $allowed));
    }

    /**
     * Forget everything resolved so far. Tests that swap the configured list
     * between cases need this; application code never does.
     */
    public function flush(): void
    {
        $this->registered = [];
        $this->factories = [];
        $this->configured = [];
        $this->loaded = false;
    }

    /**
     * Resolve the configured source classes, once.
     *
     * Instantiation must stay cheap and side-effect free: this runs the first
     * time anything asks for a source, which may be inside a console command
     * running against a database that does not exist yet.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        foreach ((array) config('filament-ai.sources', []) as $entry) {
            $source = $entry instanceof KnowledgeSource
                ? $entry
                : (is_string($entry) && is_a($entry, KnowledgeSource::class, true) ? app($entry) : null);

            if (! $source instanceof KnowledgeSource) {
                throw InvalidSourceConfigurationException::notASource($entry);
            }

            $this->configured[$source->key()] = $source;
        }
    }
}
