<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Embeddings;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;
use Murkrow\FilamentAi\Contracts\EmbeddingProvider;

/**
 * @method EmbeddingProvider driver(string|null $driver = null)
 */
final class EmbeddingManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('filament-ai.embeddings.driver', 'laravel-ai');
    }

    public function createLaravelAiDriver(): EmbeddingProvider
    {
        return LaravelAiEmbeddingProvider::fromConfig();
    }

    public function createFakeDriver(): EmbeddingProvider
    {
        return FakeEmbeddingProvider::fromConfig();
    }

    /**
     * Register a custom provider, e.g. an in-house inference service.
     *
     * @param  Closure(Container): EmbeddingProvider  $callback
     */
    public function register(string $name, Closure $callback): self
    {
        $this->extend($name, $callback);

        return $this;
    }
}
