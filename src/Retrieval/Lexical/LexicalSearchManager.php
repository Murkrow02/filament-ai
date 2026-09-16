<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval\Lexical;

use Closure;
use Illuminate\Support\Manager;
use Murkrow\FilamentAi\Contracts\LexicalSearch;

/**
 * @method LexicalSearch driver(string|null $driver = null)
 */
final class LexicalSearchManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('rag.retrieval.hybrid.driver');

        return $driver === null || $driver === '' ? 'null' : (string) $driver;
    }

    public function createNullDriver(): LexicalSearch
    {
        return new NullLexicalSearch;
    }

    public function createTsvectorDriver(): LexicalSearch
    {
        return new TsVectorLexicalSearch;
    }

    public function createScoutDriver(): LexicalSearch
    {
        return new ScoutLexicalSearch;
    }

    /**
     * @param  Closure(\Illuminate\Contracts\Container\Container): LexicalSearch  $callback
     */
    public function register(string $name, Closure $callback): self
    {
        $this->extend($name, $callback);

        return $this;
    }
}
