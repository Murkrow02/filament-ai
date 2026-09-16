<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Embeddings;

use Closure;
use Illuminate\Support\Manager;
use Murkrow\FilamentAi\Contracts\EmbeddingProvider;

/**
 * @method EmbeddingProvider driver(string|null $driver = null)
 */
final class EmbeddingManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('rag.embeddings.driver', 'laravel-ai');
    }

    public function createLaravelAiDriver(): EmbeddingProvider
    {
        return LaravelAiEmbeddingProvider::fromConfig();
    }

    /**
     * @deprecated Use the laravel-ai driver. Removed in the next minor release.
     */
    public function createPrismDriver(): EmbeddingProvider
    {
        return PrismEmbeddingProvider::fromConfig();
    }

    public function createFakeDriver(): EmbeddingProvider
    {
        return FakeEmbeddingProvider::fromConfig();
    }

    /**
     * Register a custom provider, e.g. an in-house inference service.
     *
     * @param  Closure(\Illuminate\Contracts\Container\Container): EmbeddingProvider  $callback
     */
    public function register(string $name, Closure $callback): self
    {
        $this->extend($name, $callback);

        return $this;
    }
}
