<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Reranking;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Contracts\Reranker;

/**
 * @method Reranker driver(string|null $driver = null)
 */
final class RerankerManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('filament-ai.retrieval.rerank.driver');

        return $driver === null || $driver === '' ? 'null' : (string) $driver;
    }

    public function createNullDriver(): Reranker
    {
        return new NullReranker;
    }

    public function createNoneDriver(): Reranker
    {
        return new NullReranker;
    }

    public function createOllamaDriver(): Reranker
    {
        return new OllamaReranker;
    }

    public function createLlmDriver(): Reranker
    {
        return new LlmReranker($this->container->make(LanguageModel::class));
    }

    /**
     * @param  Closure(Container): Reranker  $callback
     */
    public function register(string $name, Closure $callback): self
    {
        $this->extend($name, $callback);

        return $this;
    }
}
