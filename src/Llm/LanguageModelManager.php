<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Llm;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;
use Murkrow\FilamentAi\Contracts\LanguageModel;

/**
 * @method LanguageModel driver(string|null $driver = null)
 */
final class LanguageModelManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('filament-ai.llm.driver', 'laravel-ai');
    }

    public function createLaravelAiDriver(): LanguageModel
    {
        return LaravelAiLanguageModel::fromConfig();
    }

    public function createFakeDriver(): LanguageModel
    {
        return new FakeLanguageModel;
    }

    /**
     * @param  Closure(Container): LanguageModel  $callback
     */
    public function register(string $name, Closure $callback): self
    {
        $this->extend($name, $callback);

        return $this;
    }
}
