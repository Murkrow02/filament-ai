<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Murkrow\FilamentAi\Answering\BladePromptRenderer;
use Murkrow\FilamentAi\Answering\DefaultAnswerer;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Chunking\SlidingWindowChunker;
use Murkrow\FilamentAi\Chunking\TokenEstimatorFactory;
use Murkrow\FilamentAi\Console;
use Murkrow\FilamentAi\Contracts\Answerer;
use Murkrow\FilamentAi\Contracts\Chunker;
use Murkrow\FilamentAi\Agent\Sandbox\SandboxManager;
use Murkrow\FilamentAi\Agent\Solving\JudgeVerifier;
use Murkrow\FilamentAi\Agent\Solving\Solver;
use Murkrow\FilamentAi\Contracts\CodeSandbox;
use Murkrow\FilamentAi\Contracts\Verifier;
use Murkrow\FilamentAi\Contracts\EmbeddingProvider;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Contracts\PromptRenderer;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Embeddings\EmbeddingManager;
use Murkrow\FilamentAi\Http\Middleware\AuthorizeRagChat;
use Murkrow\FilamentAi\Embeddings\EmbeddingRateLimiter;
use Murkrow\FilamentAi\Llm\LanguageModelManager;
use Murkrow\FilamentAi\Mcp\KnowledgeServer;
use Murkrow\FilamentAi\Retrieval\DefaultRetriever;
use Murkrow\FilamentAi\Retrieval\Lexical\LexicalSearchManager;
use Murkrow\FilamentAi\Settings\SettingsRepository;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Support\Arr;
use Murkrow\FilamentAi\VectorStores\VectorStoreManager;

/**
 * Wires the package together.
 *
 * Two rules govern everything here. Optional integrations (Filament, MCP) are
 * only ever touched behind a `class_exists()` check plus a config toggle, so a
 * host without them boots normally and a breaking upgrade downstream degrades
 * to "feature off" rather than a fatal error. And nothing hits the database
 * during registration -- `migrate` has to be able to run on a schema that does
 * not exist yet.
 */
class FilamentAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->patchConversationReplay();

        $this->mergeRagConfig();

        $this->registerManagers();
        $this->registerServices();
    }

    /**
     * Merge the package defaults under the host's config, recursively.
     *
     * Laravel's own mergeConfigFrom only merges the top level, so a published
     * config file has to repeat every nested default or lose it. Merging deep
     * -- with list arrays replaced wholesale, never concatenated -- lets the
     * application's config/rag.php carry only what it actually overrides.
     */
    private function mergeRagConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/rag.php';

        /** @var array<string, mixed> $host */
        $host = (array) $this->app['config']->get('rag', []);

        $this->app['config']->set('rag', Arr::mergeConfig($defaults, $host));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rag');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'rag');

        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\MakeSourceCommand::class,
                Console\IngestCommand::class,
                Console\SearchCommand::class,
                Console\AskCommand::class,
                Console\StatusCommand::class,
                Console\PurgeCommand::class,
                Console\SourcesCommand::class,
                Console\VectorInstallCommand::class,
                Console\VectorReindexCommand::class,
            ]);
        }

        $this->registerVectorSchemaMacros();

        EmbeddingRateLimiter::register();

        $this->applySettings();

        $this->registerMcpServer();
        $this->registerChat();
    }

    /**
     * Database-backed overrides layer onto config, so the rest of the package
     * keeps reading plain config() and stays trivially testable.
     *
     * Applying them once at boot is only right for a process that dies with
     * the request. Octane and Horizon keep a booted application alive for
     * thousands of them, and there a setting saved in the panel would not take
     * effect until the next deploy -- which is exactly how it looked: the form
     * saved, the page kept behaving as before. So every request, task and job
     * re-reads them.
     */
    private function applySettings(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(SettingsRepository::class)->apply();
        });

        $events = array_values(array_filter([
            \Laravel\Octane\Events\RequestReceived::class,
            \Laravel\Octane\Events\TaskReceived::class,
            \Laravel\Octane\Events\TickReceived::class,
        ], static fn (string $event): bool => class_exists($event)));

        $events[] = \Illuminate\Queue\Events\JobProcessing::class;

        Event::listen($events, static function (object $event): void {
            // Octane hands the request its own container; writing the config
            // into that one keeps the change scoped to the request, the way
            // Octane expects.
            $container = property_exists($event, 'sandbox') ? $event->sandbox : app();

            $container->make(SettingsRepository::class)->refresh();
        });
    }

    /**
     * Swap laravel/ai's database conversation store for one that replays a
     * paused multi-step turn correctly. See ReplaySafeConversationStore.
     *
     * Only the stock store is replaced: a host that bound its own keeps it.
     */
    private function patchConversationReplay(): void
    {
        if (! interface_exists(\Laravel\Ai\Contracts\ConversationStore::class)) {
            return;
        }

        $this->app->extend(\Laravel\Ai\Contracts\ConversationStore::class, static function (object $store): object {
            return $store::class === \Laravel\Ai\Storage\DatabaseConversationStore::class
                ? new \Murkrow\FilamentAi\Agent\Chat\ReplaySafeConversationStore(config('ai.conversations.connection'))
                : $store;
        });
    }

    /**
     * The chat: gate abilities plus the routes it talks to.
     *
     * These routes are the transport for both surfaces -- the standalone page
     * and the panel page, which render the same component -- so they are
     * registered whenever either is switched on. `rag.chat.enabled` governs
     * only the standalone page itself, inside routes/chat.php.
     *
     * Registered here rather than in register() because the container outlives
     * the request under Octane, and because a Gate ability defined per request
     * accumulates closures. Routes are skipped when the application has cached
     * them -- the cache already contains ours.
     */
    private function registerChat(): void
    {
        $standalone = (bool) config('rag.chat.enabled', true);
        $inPanel = (bool) config('rag.agent.enabled', true) && (bool) config('rag.agent.chat.enabled', true);

        if (! config('rag.enabled', true) || (! $standalone && ! $inPanel)) {
            return;
        }

        ChatAbilities::register();

        if ($this->app->routesAreCached()) {
            return;
        }

        $path = trim((string) config('rag.chat.path', 'rag/chat'), '/');

        $group = [
            'prefix' => $path,
            'as' => 'rag.chat.',
            'middleware' => [...(array) config('rag.chat.middleware', ['web']), AuthorizeRagChat::class],
        ];

        if (($domain = config('rag.chat.domain')) !== null && $domain !== '') {
            $group['domain'] = (string) $domain;
        }

        Route::group($group, function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/chat.php');
        });
    }

    /**
     * pgvector's own service provider registers the Blueprint macros this
     * package's migration needs. Registering them here too costs nothing and
     * makes the package work in contexts where that provider is not discovered
     * -- Testbench, for one, and any host that disables auto-discovery.
     */
    private function registerVectorSchemaMacros(): void
    {
        if (! class_exists(\Pgvector\Laravel\Schema::class)) {
            return;
        }

        if (Blueprint::hasMacro('vector')) {
            return;
        }

        \Pgvector\Laravel\Schema::register();
    }

    private function registerManagers(): void
    {
        $this->app->singleton(EmbeddingManager::class);
        $this->app->singleton(LanguageModelManager::class);
        $this->app->singleton(VectorStoreManager::class);
        $this->app->singleton(LexicalSearchManager::class);

        $this->app->singleton(
            EmbeddingProvider::class,
            static fn ($app): EmbeddingProvider => $app->make(EmbeddingManager::class)->driver(),
        );

        $this->app->singleton(
            LanguageModel::class,
            static fn ($app): LanguageModel => $app->make(LanguageModelManager::class)->driver(),
        );

        $this->app->singleton(SandboxManager::class);

        $this->app->singleton(Solver::class);

        // The shipped verifier is a language model. An application that
        // knows what correct means binds its own and pays nothing.
        $this->app->bind(Verifier::class, JudgeVerifier::class);

        $this->app->singleton(
            CodeSandbox::class,
            static fn ($app): CodeSandbox => $app->make(SandboxManager::class)->driver(),
        );

        $this->app->singleton(
            VectorStore::class,
            static fn ($app): VectorStore => $app->make(VectorStoreManager::class)->driver(),
        );
    }

    private function registerServices(): void
    {
        $this->app->singleton(SourceRegistry::class);
        $this->app->singleton(SettingsRepository::class);
        $this->app->singleton(TokenEstimatorFactory::class);

        $this->app->singleton(Chunker::class, SlidingWindowChunker::class);
        $this->app->singleton(PromptRenderer::class, BladePromptRenderer::class);
        $this->app->singleton(Retriever::class, DefaultRetriever::class);
        $this->app->singleton(Answerer::class, DefaultAnswerer::class);

        $this->app->singleton(RagManager::class);
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/rag.php' => config_path('rag.php'),
        ], 'rag-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/rag'),
        ], 'rag-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/rag'),
        ], 'rag-lang');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'rag-migrations');

        $this->publishes([
            __DIR__.'/../routes/ai.php' => base_path('routes/ai.php'),
        ], 'rag-ai-routes');

        $this->publishes([
            __DIR__.'/../routes/chat.php' => base_path('routes/rag-chat.php'),
        ], 'rag-chat-routes');

        // Optional: the chat serves these from the package by default.
        $this->publishes([
            __DIR__.'/../resources/dist' => public_path('vendor/rag'),
        ], 'rag-chat-assets');

        $this->publishes([
            __DIR__.'/../stubs' => base_path('stubs/rag'),
        ], 'rag-stubs');
    }

    /**
     * Registers the MCP server without requiring the host to publish routes.
     *
     * laravel/mcp is a beta dependency, so every reference to it is guarded:
     * a breaking change there must turn MCP off, not break the application.
     */
    private function registerMcpServer(): void
    {
        if (! config('rag.enabled', true) || ! config('rag.mcp.enabled', true)) {
            return;
        }

        if (! class_exists(\Laravel\Mcp\Facades\Mcp::class) || ! class_exists(\Laravel\Mcp\Server::class)) {
            return;
        }

        if (config('rag.mcp.web.enabled', true)) {
            \Laravel\Mcp\Facades\Mcp::web(
                (string) config('rag.mcp.web.path', 'mcp/knowledge'),
                KnowledgeServer::class,
            )->middleware((array) config('rag.mcp.web.middleware', []));
        }

        if (config('rag.mcp.local.enabled', true)) {
            \Laravel\Mcp\Facades\Mcp::local(
                (string) config('rag.mcp.local.handle', 'knowledge'),
                KnowledgeServer::class,
            );
        }
    }
}
