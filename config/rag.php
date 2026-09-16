<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Chunking\HeuristicTokenEstimator;
use Murkrow\FilamentAi\Chunking\Normalizers\CollapseWhitespace;
use Murkrow\FilamentAi\Chunking\Normalizers\DehyphenateLineBreaks;
use Murkrow\FilamentAi\Chunking\Normalizers\FixOcrLigatures;
use Murkrow\FilamentAi\Chunking\Normalizers\StripControlChars;

return [

    'enabled' => env('RAG_ENABLED', true),

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
        'connection' => env('RAG_DB_CONNECTION'),
        'prefix' => env('RAG_TABLE_PREFIX', 'rag_'),
        'tables' => [
            'documents' => 'documents',
            'chunks' => 'chunks',
            'runs' => 'ingestion_runs',
            'run_items' => 'ingestion_run_items',
            'settings' => 'settings',
            'queries' => 'queries',
            'citations' => 'query_citations',
            'conversations' => 'conversations',
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
    | the pgvector column, so changing it requires `rag:vector:reindex`.
    |
    */

    'embeddings' => [
        'driver' => env('RAG_EMBEDDING_DRIVER', 'laravel-ai'), // laravel-ai | prism (deprecated) | fake
        'provider' => env('RAG_EMBEDDING_PROVIDER'),
        // Read only by the deprecated prism driver.
        'prism_provider' => env('RAG_EMBEDDING_PROVIDER', 'openai'),
        'model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
        // Seconds per request; null keeps laravel/ai's default.
        'timeout' => env('RAG_EMBEDDING_TIMEOUT'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),
        // Texts per embedding request. A queued job carries
        // rag.queue.chunks_per_job chunks and sends them in requests of this
        // size. Keep it small (1-2) for a self-hosted embedder: Ollama serves
        // one request at a time, so a search waits behind the one in flight.
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 96),
        'max_input_tokens' => (int) env('RAG_EMBEDDING_MAX_INPUT_TOKENS', 8000),

        // L2-normalise vectors on write so cosine similarity == dot product.
        'normalize' => true,

        // Some open models expect asymmetric prefixes (e5, bge, nomic, ...).
        'document_prefix' => env('RAG_EMBEDDING_DOC_PREFIX', ''),
        'query_prefix' => env('RAG_EMBEDDING_QUERY_PREFIX', ''),

        'cache_queries' => true,
        'query_cache_ttl' => 3600,

        // Passed straight through to Prism's withProviderOptions().
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
        'driver' => env('RAG_LLM_DRIVER', 'laravel-ai'), // laravel-ai | prism (deprecated) | fake
        // An entry of config/ai.php's "providers"; null uses ai.default.
        'provider' => env('RAG_LLM_PROVIDER'),
        // Read only by the deprecated prism driver.
        'prism_provider' => env('RAG_LLM_PROVIDER', 'openai'),
        'model' => env('RAG_LLM_MODEL', 'gpt-4o-mini'),
        // Seconds per request; null keeps laravel/ai's default.
        'timeout' => env('RAG_LLM_TIMEOUT'),

        // Selectable at query time (e.g. the Filament Playground's model
        // dropdown). All options share the single provider above -- a
        // per-call `model` override, not a provider override. Empty means no
        // picker: callers just get the 'model' key above.
        'available_models' => [],
        // Empty disables it (required by Claude's Fable/Opus/Sonnet 5 tier, which reject the parameter with a 400).
        'temperature' => env('RAG_LLM_TEMPERATURE', '0.1') === '' ? null : (float) env('RAG_LLM_TEMPERATURE', '0.1'),
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
        'driver' => env('RAG_VECTOR_DRIVER', 'pgvector'),

        'drivers' => [
            'pgvector' => [
                'type' => env('RAG_PGVECTOR_TYPE', 'vector'), // vector | halfvec
                'index' => env('RAG_PGVECTOR_INDEX', 'hnsw'), // hnsw | ivfflat | none
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
            'driver' => env('RAG_HYBRID_DRIVER'), // null | tsvector | scout
            'candidates' => 100,
            'rrf_k' => 60,
            'weight' => 0.35,
            'tsvector_language' => env('RAG_TSVECTOR_LANGUAGE', 'italian'),
        ],

        'log_queries' => true,
        'log_retention_days' => 90,
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
        'system_view' => 'rag::prompts.system',
        'context_view' => 'rag::prompts.context',
        'user_view' => 'rag::prompts.user',
        'language' => env('RAG_LANGUAGE', 'en'),
        'require_citations' => true,
        'refusal_message' => null, // null => localised default from rag::rag.refusal
        'max_context_tokens' => 6000,
        'stream' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('RAG_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'queue' => env('RAG_QUEUE', 'rag'),
        'chunks_per_job' => (int) env('RAG_CHUNKS_PER_JOB', 96),
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
    |     php artisan rag:make:source BookSource --model=App\Models\Book
    |
    | and list it here. Each is resolved through the container, so it may take
    | constructor dependencies, and its `key()` is what `rag:ingest` and every
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
        'enabled' => env('RAG_MCP_ENABLED', true),

        'server' => [
            'name' => env('RAG_MCP_NAME', 'knowledge'),
            'version' => '1.0.0',
            'instructions' => null, // null => localised default
        ],

        'web' => [
            'enabled' => env('RAG_MCP_WEB_ENABLED', true),
            'path' => env('RAG_MCP_WEB_PATH', 'mcp/knowledge'),
            'middleware' => ['auth:sanctum'],
        ],

        'local' => [
            'enabled' => env('RAG_MCP_LOCAL_ENABLED', true),
            'handle' => env('RAG_MCP_LOCAL_HANDLE', 'knowledge'),
        ],

        'tools' => [
            'search' => ['enabled' => true, 'name' => env('RAG_MCP_TOOL_SEARCH', 'search_knowledge')],
            'fetch' => ['enabled' => true, 'name' => env('RAG_MCP_TOOL_FETCH', 'fetch_document')],
            'answer' => ['enabled' => true, 'name' => env('RAG_MCP_TOOL_ANSWER', 'answer_question')],
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
        'enabled' => env('RAG_AGENT_ENABLED', true),

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
        // model configured above, so a host that set RAG_LLM_* once does not
        // have to say it twice; null then leaves laravel/ai's own defaults
        // (config/ai.php) in charge.
        'provider' => env('RAG_AGENT_PROVIDER'),
        'model' => env('RAG_AGENT_MODEL'),

        // fn (?Authenticatable $user): bool -- who may use the assistant.
        // null lets every user who can reach the panel use it.
        'authorize' => null,

        // How many tool round trips one answer may take. A sandbox needs
        // several (write, run, read the error, fix); null keeps
        // laravel/ai's own default.
        'max_steps' => env('RAG_AGENT_MAX_STEPS'),

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
            'enabled' => (bool) env('RAG_AGENT_SANDBOX', false),
            'driver' => env('RAG_AGENT_SANDBOX_DRIVER', 'piston'), // piston | fake
            'url' => env('RAG_AGENT_SANDBOX_URL', 'http://piston:2000'),

            // Language => version selector Piston understands; '*' takes
            // whatever is installed, which is worth pinning in production.
            'languages' => [
                'python' => env('RAG_AGENT_SANDBOX_PYTHON', '*'),
            ],

            'timeout' => (int) env('RAG_AGENT_SANDBOX_TIMEOUT', 5000), // ms of wall clock per run
            'memory_limit' => 128 * 1024 * 1024,
            'http_timeout' => 15, // seconds to wait for the sandbox itself

            // Characters of stdout and of stderr handed back to the model.
            'max_output' => 4000,
            'max_code_characters' => 20000,

            // Every run is logged. null uses the application's default channel.
            'log_channel' => env('RAG_AGENT_SANDBOX_LOG'),
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
            // A button next to global search that opens the chat about the
            // record on screen.
            'topbar_button' => true,
        ],

        // The admin page that edits everything above at runtime. It is
        // gated by rag.filament.authorize, not by rag.agent.authorize:
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
    | provider, the stylesheet and Alpine build ship inside the package, and
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
    | Anything the host registers itself with Gate::define('rag.chat.<name>')
    | wins over this file entirely.
    |
    */

    'chat' => [
        'enabled' => env('RAG_CHAT_ENABLED', true),

        'path' => env('RAG_CHAT_PATH', 'rag/chat'),
        'domain' => env('RAG_CHAT_DOMAIN'),

        // The panel here is often mounted at the site root, so the chat gets
        // its own prefix rather than sharing the panel's routing space.
        'middleware' => ['web', 'auth'],

        // Applied to the ask endpoint only: "max,minutes", or null to disable.
        'throttle' => env('RAG_CHAT_THROTTLE', '30,1'),

        'layout' => 'rag::chat.layout',

        'brand' => [
            'name' => env('RAG_CHAT_BRAND'),
            'logo' => env('RAG_CHAT_LOGO'),
            'accent' => env('RAG_CHAT_ACCENT', '#2f6f4f'),
        ],

        // How many previous turns of the conversation are replayed into the
        // prompt. Every turn costs input tokens on every subsequent question,
        // so this is a budget, not a memory setting.
        'history_turns' => 6,

        // Shown on the empty state. Empty => the localised defaults.
        'suggestions' => [],

        // Newest conversations kept per user; older ones are pruned on write.
        'max_conversations' => 200,

        'abilities' => [
            'view' => null,
            'history' => null,
            'delete' => null,
            'model' => null,
            'sources' => null,
            'passages' => null,
            'cost' => null,
            'advanced' => null,
            'feedback' => null,
            'export' => null,
            'all_conversations' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament panel
    |--------------------------------------------------------------------------
    */

    'filament' => [
        'enabled' => env('RAG_FILAMENT_ENABLED', true),
        'navigation_group' => 'Knowledge',
        'navigation_sort' => 90,
        'slug_prefix' => 'rag',
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
    | `rag:vector:reindex` plus a full re-embed.
    |
    */

    'settings' => [
        'enabled' => true,
        'cache_key' => 'rag.settings',
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
            'agent.max_steps' => ['type' => 'int', 'min' => 1, 'max' => 40],
            // The sandbox URL and driver are deliberately not here: a web form
            // that decides where the application posts code is an SSRF waiting
            // to happen, and whoever administers the panel is not necessarily
            // whoever controls the network.
        ],
    ],
];
