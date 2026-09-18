<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\VectorStores;

use Closure;
use Illuminate\Support\Manager;
use Murkrow\FilamentAi\Contracts\VectorStore;

/**
 * @method VectorStore driver(string|null $driver = null)
 */
final class VectorStoreManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('filament-ai.vector.driver', 'pgvector');
    }

    public function createPgvectorDriver(): VectorStore
    {
        return new PgVectorStore;
    }

    /**
     * @param  Closure(\Illuminate\Contracts\Container\Container): VectorStore  $callback
     */
    public function register(string $name, Closure $callback): self
    {
        $this->extend($name, $callback);

        return $this;
    }
}
