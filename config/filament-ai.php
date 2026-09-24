<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Chunking\HeuristicTokenEstimator;
use Murkrow\FilamentAi\Chunking\Normalizers\CollapseWhitespace;
use Murkrow\FilamentAi\Chunking\Normalizers\DehyphenateLineBreaks;
use Murkrow\FilamentAi\Chunking\Normalizers\FixOcrLigatures;
use Murkrow\FilamentAi\Chunking\Normalizers\StripControlChars;

return [

    'enabled' => env('FILAMENT_AI_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Every table this package creates lives behind a configurable prefix so it
    | can never collide with the host application's schema. Leave "connection"
    | null to use the application's default connection.
    |
    */

    'database' => [
        'connection' => env('FILAMENT_AI_DB_CONNECTION'),
        'prefix' => env('FILAMENT_AI_TABLE_PREFIX', 'rag_'),
        'tables' => [
            'documents' => 'documents',
            'chunks' => 'chunks',
            'runs' => 'ingestion_runs',
            'run_items' => 'ingestion_run_items',
            'settings' => 'settings',
            'queries' => 'queries',
            'citations' => 'query_citations',
            'conversations' => 'conversations',
            'solve_runs' => 'solve_runs',
            'solve_attempts' => 'solve_attempts',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Provider agnostic through laravel/ai: "provider" names an entry of
    | config/ai.php's "providers" (null uses ai.default_for_embeddings), so
    | keys and base URLs are configured once for the whole application.
    | "dimensions" MUST match what the model returns -- it defines the width of
    | the pgvector column, so changing it requires `ai:vector:reindex`.
    |
    */

    'embeddings' => [
        'driver' => env('FILAMENT_AI_EMBEDDING_DRIVER', 'laravel-ai'), // laravel-ai | fake
        'provider' => env('FILAMENT_AI_EMBEDDING_PROVIDER'),
        'model' => env('FILAMENT_AI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        // Seconds per request; null keeps laravel/ai's default.
        'timeout' => env('FILAMENT_AI_EMBEDDING_TIMEOUT'),
        'dimensions' => (int) env('FILAMENT_AI_EMBEDDING_DIMENSIONS', 1536),
        // Texts per embedding request. A queued job carries
        // filament-ai.queue.chunks_per_job chunks and sends them in requests of this
        // size. Keep it small (1-2) for a self-hosted embedder: Ollama serves
        // one request at a time, so a search waits behind the one in flight.
        'batch_size' => (int) env('FILAMENT_AI_EMBEDDING_BATCH_SIZE', 96),
        'max_input_tokens' => (int) env('FILAMENT_AI_EMBEDDING_MAX_INPUT_TOKENS', 8000),

        // L2-normalise vectors on write so cosine similarity == dot product.
        'normalize' => true,

        // Some open models expect asymmetric prefixes (e5, bge, nomic, ...).
        'document_prefix' => env('FILAMENT_AI_EMBEDDING_DOC_PREFIX', ''),
        'query_prefix' => env('FILAMENT_AI_EMBEDDING_QUERY_PREFIX', ''),

        'cache_queries' => true,
        'query_cache_ttl' => 3600,

        // Passed straight through to laravel/ai's withProviderOptions().
        'provider_options' => [],

        // USD per 1M tokens, used for cost accounting only.
        'pricing' => [
            'text-embedding-3-small' => 0.02,
            'text-embedding-3-large' => 0.13,
            'voyage-4' => 0.06,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation model
    |--------------------------------------------------------------------------
    */

    'llm' => [
        'driver' => env('FILAMENT_AI_LLM_DRIVER', 'laravel-ai'), // laravel-ai | fake
        // An entry of config/ai.php's "providers"; null uses ai.default.
        'provider' => env('FILAMENT_AI_LLM_PROVIDER'),
        'model' => env('FILAMENT_AI_LLM_MODEL', 'gpt-4o-mini'),
        // Seconds per request; null keeps laravel/ai's default.
        'timeout' => env('FILAMENT_AI_LLM_TIMEOUT'),

        // Selectable at query time (e.g. the Filament Playground's model
        // dropdown). All options share the single provider above -- a
        // per-call `model` override, not a provider override. Empty means no
        // picker: callers just get the 'model' key above.
        'available_models' => [],
        // Empty disables it (required by Claude's Fable/Opus/Sonnet 5 tier, which reject the parameter with a 400).
        'temperature' => env('FILAMENT_AI_LLM_TEMPERATURE', '0.1') === '' ? null : (float) env('FILAMENT_AI_LLM_TEMPERATURE', '0.1'),
        'max_tokens' => 1200,
        'provider_options' => [],

        // USD per 1M tokens.
        'pricing' => [
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
            'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector store
    |--------------------------------------------------------------------------
    |
    | Only the pgvector driver ships today. The VectorStore contract exists so
    | another backend can be added as a single class without a refactor.
    |
    */

    'vector' => [
        'driver' => env('FILAMENT_AI_VECTOR_DRIVER', 'pgvector'),

        'drivers' => [
            'pgvector' => [
                'type' => env('FILAMENT_AI_PGVECTOR_TYPE', 'vector'), // vector | halfvec
                'index' => env('FILAMENT_AI_PGVECTOR_INDEX', 'hnsw'), // hnsw | ivfflat | none
                'ops' => 'vector_cosine_ops',
                'hnsw' => ['m' => 16, 'ef_construction' => 64, 'ef_search' => 100],
                'ivfflat' => ['lists' => 1000, 'probes' => 10],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | The chunker consumes an ordered stream of segments (one per page) and
    | emits overlapping windows that may span a segment boundary, so a sentence
    | cut in half by a page break is never truncated.
    |
    */

    'chunking' => [
        'target_tokens' => 512,
        'max_tokens' => 900,

        // Trailing chunks shorter than this are merged backwards instead of
        // being emitted as high-similarity, low-content stubs.
        'min_tokens' => 48,

        'overlap_tokens' => 80,

        // Safety valve for OCR text that contains no sentence punctuation at
        // all: a "sentence" longer than this is split on whitespace.
        'hard_split_chars' => 480,

        // Stitch a sentence that runs across two segments back together.
        'bridge_segments' => true,

        'sentence_regex' => '/(?<=[.!?\x{2026}])\s+(?=[\x{00AB}"\x{201C}(\[]?[A-Z\x{00C0}-\x{00DE}0-9])/u',

        'token_estimator' => HeuristicTokenEstimator::class,

        // Empirical characters-per-token for Latin-script European languages.
        'chars_per_token' => 3.7,

        'normalizers' => [
            StripControlChars::class,
            FixOcrLigatures::class,
            DehyphenateLineBreaks::class,
            CollapseWhitespace::class,
        ],

        // Prepend a short provenance header to the embedded text. Improves
        // retrieval on generic chunks at the cost of a few tokens each.
        'embed_context_header' => true,
        'context_header' => ':document_title - :position_label',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    'retrieval' => [
        'top_k' => 8,

        // Over-fetch before de-duplication and MMR re-ranking.
        'fetch_k' => 40,

        'min_score' => 0.25,

        'mmr' => ['enabled' => true, 'lambda' => 0.6],

        // Adjacent chunks share `overlap_tokens` by design, so near-duplicate
        // collapsing is mandatory rather than optional.
        'dedupe_threshold' => 0.97,

        // Pull ordinal +/- n around every hit to restore surrounding context.
        'expand_neighbors' => 0,

        'hybrid' => [
            'driver' => env('FILAMENT_AI_HYBRID_DRIVER'), // null | tsvector | scout
            'candidates' => 100,
            'rrf_k' => 60,
            'weight' => 0.35,
            'tsvector_language' => env('FILAMENT_AI_TSVECTOR_LANGUAGE', 'italian'),
        ],

        'log_queries' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Answering
    |--------------------------------------------------------------------------
    |
    | Prompts are Blade views so they can be published and rewritten without
    | touching the package.
    |
    */

    'answering' => [
        'system_view' => 'filament-ai::prompts.system',
        'context_view' => 'filament-ai::prompts.context',
        'user_view' => 'filament-ai::prompts.user',
        'language' => env('FILAMENT_AI_LANGUAGE', 'en'),
        'require_citations' => true,
        'refusal_message' => null, // null => localised default from filament-ai::messages.refusal
        'max_context_tokens' => 6000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('FILAMENT_AI_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        // The queue name keeps its old default on purpose: renaming it would
        // strand whatever is already queued under the old one, and the host's
        // worker configuration with it.
        'queue' => env('FILAMENT_AI_QUEUE', 'rag'),
        'chunks_per_job' => (int) env('FILAMENT_AI_CHUNKS_PER_JOB', 96),
        'tries' => 5,
        'backoff' => [10, 30, 60, 120, 300],
        'timeout' => 300,
        'allow_failures' => true,
        'rate_limit' => ['requests' => 500, 'per_seconds' => 60],
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge sources
    |--------------------------------------------------------------------------
    |
    | Sources are classes, not configuration. Generate one with
    |
    |     php artisan ai:make:source BookSource --model=App\Models\Book
    |
    | and list it here. Each is resolved through the container, so it may take
    | constructor dependencies, and its `key()` is what `ai:ingest` and every
    | stored document refer to. This list is the only place a host model is
    | reached at all: the package itself names none.
    |
    */

    'sources' => [
        // App\Knowledge\BookSource::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    |
    | Registered automatically by the service provider when laravel/mcp is
    | installed; the host does not need to publish routes/ai.php.
    |
    */

    'mcp' => [
        'enabled' => env('FILAMENT_AI_MCP_ENABLED', true),

        'server' => [
            'name' => env('FILAMENT_AI_MCP_NAME', 'knowledge'),
            'instructions' => null, // null => localised default
        ],

        'web' => [
            'enabled' => env('FILAMENT_AI_MCP_WEB_ENABLED', true),
            'path' => env('FILAMENT_AI_MCP_WEB_PATH', 'mcp/knowledge'),
            'middleware' => ['auth:sanctum'],
        ],

        'local' => [
            'enabled' => env('FILAMENT_AI_MCP_LOCAL_ENABLED', true),
            'handle' => env('FILAMENT_AI_MCP_LOCAL_HANDLE', 'knowledge'),
        ],

        'tools' => [
            'search' => ['enabled' => true, 'name' => env('FILAMENT_AI_MCP_TOOL_SEARCH', 'search_knowledge')],
            'fetch' => ['enabled' => true, 'name' => env('FILAMENT_AI_MCP_TOOL_FETCH', 'fetch_document')],
            'answer' => ['enabled' => true, 'name' => env('FILAMENT_AI_MCP_TOOL_ANSWER', 'answer_question')],
        ],

        'resources' => [
            'documents' => ['enabled' => true, 'name' => 'documents', 'limit' => 500],
        ],

        // null => every registered source; or ['books'] to restrict exposure.
        'sources' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel agent
    |--------------------------------------------------------------------------
    |
    | The assistant a panel user talks to. It reads the knowledge base and the
    | Filament resources that implement AgentResource -- exposure is opt-in per
    | resource -- always as the signed-in user, through the resource's own
    | query and policies.
    |
    */

    'agent' => [
        'enabled' => env('FILAMENT_AI_AGENT_ENABLED', true),

        'knowledge' => [
            'enabled' => true,
            // null => every registered source; [] => none.
            'sources' => null,
        ],

        'resources' => [
            'enabled' => true,
            // Default cap on records per list call; AgentTools::limit() overrides it per resource.
            'max_records' => 25,

            // Per-resource policies, keyed by resource class. What the
            // panel's assistant settings page writes; a resource absent
            // here keeps whatever its own agentTools() declared.
            //
            //   App\Filament\Resources\Orders\OrderResource::class => [
            //       'abilities' => ['list', 'view', 'edit'],
            //       'unapproved' => ['edit'],
            //       'max_records' => 10,
            //   ],
            'overrides' => [],
        ],

        // The agent class the panel chat talks to. Extend PanelAssistant to
        // give it a persona, a domain and extra tools.
        'assistant' => \Murkrow\FilamentAi\Agent\PanelAssistant::class,

        // Which model answers in the panel. Both fall back to the generation
        // model configured above, so a host that set FILAMENT_AI_LLM_* once does not
        // have to say it twice; null then leaves laravel/ai's own defaults
        // (config/ai.php) in charge.
        'provider' => env('FILAMENT_AI_AGENT_PROVIDER'),
        'model' => env('FILAMENT_AI_AGENT_MODEL'),
        // Null falls back to llm.temperature, then to the provider's default.
        'temperature' => env('FILAMENT_AI_AGENT_TEMPERATURE'),

        // fn (?Authenticatable $user): bool -- who may use the assistant.
        // null lets every user who can reach the panel use it.
        'authorize' => null,

        // How many tool round trips one answer may take. A sandbox needs
        // several (write, run, read the error, fix); null keeps
        // laravel/ai's own default.
        'max_steps' => env('FILAMENT_AI_AGENT_MAX_STEPS'),

        /*
        | Code execution.
        |
        | Off by default, and deliberately so: this hands a language model
        | a way to run programs. The driver is the security boundary -- the
        | shipped one talks to a self-hosted Piston, which runs each
        | submission under isolate with no outgoing network. Never point it
        | at something that shares this application's filesystem, database
        | or network.
        */
        'sandbox' => [
            'enabled' => (bool) env('FILAMENT_AI_AGENT_SANDBOX', false),
            'driver' => env('FILAMENT_AI_AGENT_SANDBOX_DRIVER', 'piston'), // piston | fake
            'url' => env('FILAMENT_AI_AGENT_SANDBOX_URL', 'http://piston:2000'),

            // Language => version selector Piston understands; '*' takes
            // whatever is installed, which is worth pinning in production.
            'languages' => [
                'python' => env('FILAMENT_AI_AGENT_SANDBOX_PYTHON', '*'),
            ],

            'timeout' => (int) env('FILAMENT_AI_AGENT_SANDBOX_TIMEOUT', 5000), // ms of wall clock per run
            'memory_limit' => 128 * 1024 * 1024,
            'http_timeout' => 15, // seconds to wait for the sandbox itself

            // Characters of stdout and of stderr handed back to the model.
            'max_output' => 4000,
            'max_code_characters' => 20000,

            // Every run is logged. null uses the application's default channel.
            'log_channel' => env('FILAMENT_AI_AGENT_SANDBOX_LOG'),
        ],

        /*
        | Web search.
        |
        | Off by default: this hands the agent a way to reach the open
        | internet. The shipped driver is Google's Programmable Search
        | Engine (Custom Search JSON API) -- create an engine at
        | https://programmablesearchengine.google.com set to search the
        | whole web, and an API key at
        | https://console.cloud.google.com/apis/credentials with the
        | "Custom Search API" enabled.
        |
        | search_web only ever returns titles, urls and snippets: fetching a
        | full page is a second, separate tool (fetch_web_page), so the agent
        | spends a request reading a page only for the one result it decided
        | is worth it, not for all ten every time. That split, plus caching
        | identical queries for `cache_ttl` seconds, is what keeps this
        | efficient rather than just functional.
        */
        'web_search' => [
            'enabled' => (bool) env('FILAMENT_AI_AGENT_WEB_SEARCH', false),

            // google | fake, or a driver registered on WebSearchManager.
            'driver' => env('FILAMENT_AI_AGENT_WEB_SEARCH_DRIVER', 'google'),

            'google' => [
                'api_key' => env('FILAMENT_AI_GOOGLE_SEARCH_API_KEY'),
                'cx' => env('FILAMENT_AI_GOOGLE_SEARCH_CX'),
                'endpoint' => env('FILAMENT_AI_GOOGLE_SEARCH_ENDPOINT', 'https://www.googleapis.com/customsearch/v1'),
                'timeout' => (int) env('FILAMENT_AI_GOOGLE_SEARCH_TIMEOUT', 8),
            ],

            // Results per call when the model does not say how many; Google
            // never returns more than 10 regardless of what is asked.
            'default_results' => (int) env('FILAMENT_AI_AGENT_WEB_SEARCH_RESULTS', 5),

            // Identical (query, limit) pairs are served from cache instead of
            // billed again. Zero disables caching.
            'cache_ttl' => (int) env('FILAMENT_AI_AGENT_WEB_SEARCH_CACHE_TTL', 3600),

            // Reading a whole page means fetching a url the model chose from
            // this server. It is guarded (public addresses only, pinned, size
            // capped) but it is a separate decision: off unless switched on.
            'fetch_page' => [
                'enabled' => (bool) env('FILAMENT_AI_AGENT_WEB_FETCH', false),
                'timeout' => (int) env('FILAMENT_AI_AGENT_WEB_FETCH_TIMEOUT', 8),
                'max_bytes' => (int) env('FILAMENT_AI_AGENT_WEB_FETCH_MAX_BYTES', 200000),
                'max_output_characters' => (int) env('FILAMENT_AI_AGENT_WEB_FETCH_MAX_OUTPUT', 6000),
            ],
        ],

        // The chat page inside the panel. It keeps its history in laravel/ai's
        // conversation tables: publish and run laravel/ai's migrations.
        'chat' => [
            'enabled' => true,
            'slug' => 'assistant',
            'navigation_group' => null,
            'navigation_sort' => -1,
            // Conversations listed in the chat's sidebar.
            'history' => 20,
            // Decisions on one conversation are taken one at a time, under a
            // cache lock held while the turn streams: a double click must not
            // run an approved write twice. Needs a cache store with locks.
            'turn_lock_seconds' => 600,
            // A button next to global search that opens the chat about the
            // record on screen.
            'topbar_button' => true,
        ],

        /*
        | Iterative solving.
        |
        | The assistant answers in one turn; this is the other mode, for
        | questions that need trying: several attempts run in parallel, a
        | verifier judges them, and the next wave starts from what was wrong
        | with the last. Off by default -- it multiplies the cost of an answer
        | by attempts x waves, plus one judgement each.
        */
        'solving' => [
            'enabled' => (bool) env('FILAMENT_AI_AGENT_SOLVING', false),

            'attempts_per_wave' => (int) env('FILAMENT_AI_AGENT_SOLVING_ATTEMPTS', 4),
            'max_waves' => (int) env('FILAMENT_AI_AGENT_SOLVING_WAVES', 3),

            // How a run goes about it. The default one tries the goal with
            // every tool, wave after wave, learning only from what the judge
            // rejected. An application that knows how its own problems are
            // solved -- try the anagrams, then the archive, then the map --
            // implements Contracts\SolveStrategy and names its class here;
            // its phases then decide the number of waves.
            'strategy' => \Murkrow\FilamentAi\Agent\Solving\DefaultStrategy::class,

            // How far apart the attempts of one wave are told to think. Zero
            // makes them near-copies, which wastes running several.
            'temperature_spread' => 0.4,

            // Budgets. The first one to run out ends the run, which is then
            // reported as "no solution found" with the best attempt kept.
            // null switches one off; leaving them all off is asking for a
            // surprise on the invoice.
            'max_tokens' => (int) env('FILAMENT_AI_AGENT_SOLVING_MAX_TOKENS', 300000),
            'max_cost_micros' => (int) env('FILAMENT_AI_AGENT_SOLVING_MAX_COST', 2000000), // USD 2.00
            'max_seconds' => (int) env('FILAMENT_AI_AGENT_SOLVING_MAX_SECONDS', 300),

            // The judge. A cheaper model than the one doing the work is the
            // point: null falls back to the agent's own.
            'judge' => [
                'provider' => env('FILAMENT_AI_AGENT_JUDGE_PROVIDER'),
                'model' => env('FILAMENT_AI_AGENT_JUDGE_MODEL'),
            ],

            'queue' => [
                'connection' => env('FILAMENT_AI_AGENT_SOLVING_QUEUE_CONNECTION', env('FILAMENT_AI_QUEUE_CONNECTION')),
                'queue' => env('FILAMENT_AI_AGENT_SOLVING_QUEUE', env('FILAMENT_AI_QUEUE', 'rag')),
            ],
        ],

        // The admin page that edits everything above at runtime. It is
        // gated by filament-ai.filament.authorize, not by filament-ai.agent.authorize:
        // using the assistant and deciding what it may do are different
        // permissions.
        'settings' => [
            'enabled' => true,
            'slug' => 'assistant-settings',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat page
    |--------------------------------------------------------------------------
    |
    | A standalone, Filament-free chat UI served by the package itself. It has
    | no dependency on the panel: the routes are registered from the service
    | provider, the stylesheet and script ship inside the package, and
    | the layout is a complete HTML document, so a host with no frontend
    | pipeline of its own still gets a working page.
    |
    | Every visible element is gated by an ability. Each entry under
    | "abilities" accepts four shapes:
    |
    |     true / false                       a literal answer
    |     fn (?Authenticatable $u): bool     a closure, like filament.authorize
    |     'some.permission'                  delegated to $user->can('some.permission')
    |     null                               the package default
    |
    | Anything the host registers itself with Gate::define('filament-ai.chat.<name>')
    | wins over this file entirely.
    |
    */

    'chat' => [
        'enabled' => env('FILAMENT_AI_CHAT_ENABLED', true),

        'path' => env('FILAMENT_AI_CHAT_PATH', 'ai/chat'),

        // The Filament panel the standalone page acts in (its resources, its
        // policies, its tenancy). Null means the default panel. The panel's
        // own Assistant page always sends its own panel and tenant.
        'panel' => env('FILAMENT_AI_CHAT_PANEL'),
        'domain' => env('FILAMENT_AI_CHAT_DOMAIN'),

        // The panel here is often mounted at the site root, so the chat gets
        // its own prefix rather than sharing the panel's routing space.
        'middleware' => ['web', 'auth'],

        // Applied to the ask endpoint only: "max,minutes", or null to disable.
        'throttle' => env('FILAMENT_AI_CHAT_THROTTLE', '30,1'),

        'layout' => 'filament-ai::chat.layout',

        'brand' => [
            'name' => env('FILAMENT_AI_CHAT_BRAND'),
            'logo' => env('FILAMENT_AI_CHAT_LOGO'),
            'accent' => env('FILAMENT_AI_CHAT_ACCENT', '#2f6f4f'),
        ],

        // Shown on the empty state. Empty => the localised defaults.
        'suggestions' => [],

        // Null keeps the default (see ChatAbilities::DEFAULTS): everything a
        // person needs is on, `cost` and `debug` are off. Each accepts a bool,
        // a permission name checked with $user->can(), or a [Class::class,
        // 'method'] callable; a Gate ability `filament-ai.chat.<name>` defined
        // by the host wins over all of these.
        'abilities' => [
            'view' => null,
            'history' => null,
            'delete' => null,
            'model' => null,
            'settings' => null,
            'cost' => null,
            'solve' => null,
            'export' => null,
            'debug' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament panel
    |--------------------------------------------------------------------------
    */

    'filament' => [
        'enabled' => env('FILAMENT_AI_FILAMENT_ENABLED', true),
        'navigation_group' => 'Knowledge',
        'navigation_sort' => 90,
        'slug_prefix' => 'ai',
        // How often the run views refresh *while a run is in flight*. Idle
        // pages do not poll at all: several Livewire components refreshing at
        // once can race the AuthenticateSession middleware into regenerating
        // the session, which surfaces as a "Page Expired" loop. null disables
        // polling entirely.
        'poll_interval' => '5s',

        // fn (?Authenticatable $user): bool
        'authorize' => null,

        'pages' => [
            'dashboard' => true,
            'ingest' => true,
            'settings' => true,
            'playground' => true,

            // A plain link out to the standalone chat page.
            'chat_link' => true,
        ],

        'resources' => [
            'runs' => true,
            'documents' => true,
            'queries' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime-editable settings
    |--------------------------------------------------------------------------
    |
    | Whitelisted keys can be overridden from the control panel and are stored
    | in the settings table, taking precedence over this file.
    |
    | Note: embeddings.model and embeddings.dimensions are deliberately NOT
    | overridable -- changing them invalidates every stored vector and requires
    | `ai:vector:reindex` plus a full re-embed.
    |
    */

    'settings' => [
        'enabled' => true,
        'cache_key' => 'filament-ai.settings',
        'cache_ttl' => 300,

        'overridable' => [
            'llm.model' => ['type' => 'string'],
            'llm.temperature' => ['type' => 'float', 'min' => 0, 'max' => 2],
            'llm.max_tokens' => ['type' => 'int', 'min' => 64, 'max' => 32000],
            'chunking.target_tokens' => ['type' => 'int', 'min' => 64, 'max' => 4000],
            'chunking.max_tokens' => ['type' => 'int', 'min' => 64, 'max' => 8000],
            'chunking.overlap_tokens' => ['type' => 'int', 'min' => 0, 'max' => 1000],
            'retrieval.top_k' => ['type' => 'int', 'min' => 1, 'max' => 50],
            'retrieval.fetch_k' => ['type' => 'int', 'min' => 1, 'max' => 200],
            'retrieval.min_score' => ['type' => 'float', 'min' => 0, 'max' => 1],
            'retrieval.mmr.enabled' => ['type' => 'bool'],
            'retrieval.mmr.lambda' => ['type' => 'float', 'min' => 0, 'max' => 1],
            'retrieval.expand_neighbors' => ['type' => 'int', 'min' => 0, 'max' => 5],
            'retrieval.hybrid.driver' => ['type' => 'enum', 'options' => [null, 'tsvector', 'scout']],
            'answering.refusal_message' => ['type' => 'text'],
            'answering.require_citations' => ['type' => 'bool'],
            'answering.max_context_tokens' => ['type' => 'int', 'min' => 500, 'max' => 100000],
            'queue.chunks_per_job' => ['type' => 'int', 'min' => 1, 'max' => 512],

            // The assistant. Edited by its own page, not by the knowledge
            // settings form, which skips everything under "agent.".
            'agent.enabled' => ['type' => 'bool'],
            'agent.provider' => ['type' => 'string'],
            'agent.model' => ['type' => 'string'],
            'agent.knowledge.enabled' => ['type' => 'bool'],
            'agent.knowledge.sources' => ['type' => 'json'],
            'agent.resources.enabled' => ['type' => 'bool'],
            'agent.resources.max_records' => ['type' => 'int', 'min' => 1, 'max' => 200],
            'agent.resources.overrides' => ['type' => 'json'],
            'agent.chat.enabled' => ['type' => 'bool'],
            'agent.chat.history' => ['type' => 'int', 'min' => 1, 'max' => 100],
            'agent.chat.topbar_button' => ['type' => 'bool'],
            'agent.sandbox.enabled' => ['type' => 'bool'],
            'agent.sandbox.languages' => ['type' => 'json'],
            'agent.sandbox.timeout' => ['type' => 'int', 'min' => 500, 'max' => 60000],
            'agent.sandbox.max_output' => ['type' => 'int', 'min' => 200, 'max' => 50000],
            'agent.sandbox.max_code_characters' => ['type' => 'int', 'min' => 200, 'max' => 200000],
            'agent.web_search.enabled' => ['type' => 'bool'],
            'agent.web_search.default_results' => ['type' => 'int', 'min' => 1, 'max' => 10],
            'agent.web_search.cache_ttl' => ['type' => 'int', 'min' => 0, 'max' => 86400],
            'agent.web_search.fetch_page.enabled' => ['type' => 'bool'],
            'agent.web_search.fetch_page.max_output_characters' => ['type' => 'int', 'min' => 500, 'max' => 50000],
            // The Google api_key, cx and endpoint are deliberately not here,
            // same reasoning as the sandbox url: whoever administers the
            // panel is not necessarily whoever should be able to point this
            // application's outbound requests at a different endpoint.
            'agent.max_steps' => ['type' => 'int', 'min' => 1, 'max' => 40],
            'agent.solving.enabled' => ['type' => 'bool'],
            'agent.solving.attempts_per_wave' => ['type' => 'int', 'min' => 1, 'max' => 16],
            'agent.solving.max_waves' => ['type' => 'int', 'min' => 1, 'max' => 10],
            'agent.solving.max_tokens' => ['type' => 'int', 'min' => 1000, 'max' => 5000000],
            'agent.solving.max_seconds' => ['type' => 'int', 'min' => 10, 'max' => 3600],
            // The sandbox URL and driver are deliberately not here: a web form
            // that decides where the application posts code is an SSRF waiting
            // to happen, and whoever administers the panel is not necessarily
            // whoever controls the network.
        ],
    ],
];
