# CLAUDE.md — working on `murkrow/filament-ai`

Handoff notes for an AI agent continuing this package. Read `README.md` first for what it does; this file is about how it is built and what will bite you.

## Status

Mid-migration to `murkrow/filament-ai` on branch `feat/filament-ai`: Filament v5 and laravel/ai drivers are in, the agent layer (resource-derived tools, in-panel chat, MCP parity) is not built yet. Green: 206 tests on SQLite + 14 pgvector.

**Do not merge this branch to `main` as-is.** `release.yml` would tag it as a *patch* of `murkrow/laravel-rag`, and the renamed `name` in `composer.json` conflicts with that repo's Packagist entry. Settle the new repository/Packagist package and tag a major by hand first. Hosts pinned to `murkrow/laravel-rag ^1.0` (Filament 4) stay on 1.x.

```bash
composer install
vendor/bin/pest                       # unit + feature on SQLite
vendor/bin/pest --testsuite=Pgvector  # needs a real PostgreSQL with pgvector
```

The pgvector suite skips itself when no database is reachable. Point it at one with
`RAG_TEST_PG_HOST` / `RAG_TEST_PG_PORT` (defaults: host `rag-test-pg`, port `5432`,
db `rag_test`, user/password `rag`/`rag`):

```bash
docker run -d --name rag-test-pg \
  -e POSTGRES_USER=rag -e POSTGRES_PASSWORD=rag -e POSTGRES_DB=rag_test \
  -p 55432:5432 pgvector/pgvector:pg17
RAG_TEST_PG_HOST=127.0.0.1 RAG_TEST_PG_PORT=55432 vendor/bin/pest --testsuite=Pgvector
```

CI (`.github/workflows/tests.yml`) runs the full suite, pgvector included, against a
`pgvector/pgvector:pg17` service on every push and pull request. Every push to `main`
also runs `.github/workflows/release.yml`, which auto-tags the next patch version and
cuts a GitHub release; Packagist then publishes that tag. Bump the minor or major
yourself by pushing a `vX.Y.0` tag by hand when a release warrants it.

## The four invariants

Break any of these and the package stops being what it is.

**1. No host application classes.** Nothing under `src/` may name `App\Models\Book` or anything like it. Host models are reached only from a source class the *application* writes (`app/Knowledge/BookSource.php`, extending `EloquentSource`), listed as a class-string in `config/rag.php` under `sources`. The test fixtures (`tests/Fixtures/TestBook.php`, `TestBookSource.php`) exist to prove this: if they are enough to exercise every path, the package is genuinely generic.

**2. Optional integrations stay optional, and laravel/ai stays contained.** Filament ^5 and `laravel/ai` are `require`d: the package is a Filament plugin and its agent layer is laravel/ai. `laravel/mcp` and Scout stay `require-dev` + `suggest`; every reference to them is behind `class_exists()` plus a config toggle, so a host without them boots normally and a breaking upgrade downstream degrades to "feature off" rather than a fatal error. `laravel/ai` is **0.x**: nothing outside `src/Llm/LaravelAiLanguageModel.php`, `src/Llm/LaravelAi/` and `src/Embeddings/LaravelAiEmbeddingProvider.php` may depend on its request, response or event shapes — everything else goes through the `LanguageModel` / `EmbeddingProvider` contracts.

**3. Nothing touches the database during `register()`.** `migrate` has to run against a schema that does not exist yet. `SettingsRepository::available()` guards on `Schema::hasTable()` inside a try/catch for exactly this reason, and settings are applied in `$this->app->booted()`.

**4. The chunker is pure.** Same segments plus same options must always produce byte-identical text and hashes. `ChunkerBoundaryTest > it is deterministic across runs` pins this. If it ever fails, incremental re-ingestion silently becomes a full re-embed on every run — expensive and almost invisible.

## Shape of the thing

```
Source (class)   →  DocumentIngestor  →  Chunker  →  ChunkDiffer  →  ChunkEmbedder  →  VectorStore
                                                                                            ↓
                          Answerer  ←  PromptRenderer  ←  Retriever  ←────────────────────┘
```

- `src/Sources/` — `EloquentSource` (one row, one document) and `GroupedEloquentSource` (one *group* of rows, one document, for tables of thousands of short entries) are the abstract bases a host extends (model, `SegmentMap`, `PositionLabels`, `ChunkingOverrides`, filters); `ClosureKnowledgeSource` the escape hatch; `SourceRegistry` resolves the class-strings in `rag.sources` through the container. Filters are objects (`src/Sources/Filters/`, built via `Filter::ids(...)`) that own their own `apply()`, and the *same* object drives `--filter=`, the ingestion query and the Filament form — which is why nothing in `src/Sources/` may import Filament: the field mapping lives in `Filament/Forms/SourceFilterSchema`, keyed by `instanceof`, and an unknown filter class degrades to a text input.
- `src/Chunking/` — `SentenceSplitter` produces page-tagged sentences (with OCR hard-splitting and cross-page bridging); `SlidingWindowChunker` slides a token-budgeted window over them. Read the comments there before changing anything: the `max(1, $cut)` in `windows()` is a monotonicity guard, not defensive noise.
- `src/Ingestion/` — `ChunkDiffer` is the reason re-indexing is cheap. `RunProgress` uses atomic `incrementEach`, never read-modify-write, because several workers report on the same run. `ChunkEmbedder` splits a job's group into `maxBatchSize()` calls but writes every vector in one upsert at the end: a group that fails half-way leaves nothing written, so the retry's progress accounting stays exact.
- `src/VectorStores/` — only `PgVectorStore` ships. `AbstractVectorStore::baseQuery()` owns filter compilation for every driver. `Contracts\\ResizableVectorStore` is a second, optional contract (installed column width, in-place resize) so `rag:vector:reindex` can repair a dimensions change without widening `VectorStore` for third-party drivers.
- `src/Retrieval/`, `src/Answering/` — pipeline and grounding.
- `src/Filament/` — the panel surface (Filament is required). `src/Mcp/` — the optional MCP surface.
- `src/Llm/`, `src/Embeddings/` — `laravel-ai` is the default driver, `prism` is deprecated and goes in the next minor, `fake` is for tests.
- `src/Chat/`, `src/Http/`, `routes/chat.php`, `resources/views/chat/`, `resources/dist/` — the standalone chat page. Filament-free by construction: it is the surface for someone who wants an answer, not a retriever to tune. `ChatAbilities` is its whole authorization vocabulary; `ChatPayload` is the only thing that decides what reaches the browser.

## Decisions that look odd and are not

- **`content_hash` hashes the *embedding input*, not `content`.** The provenance header (document title + position label) is part of what gets embedded, so a title change must invalidate the vector. The header is stored on the chunk's `metadata.header` so `EmbeddingInput::for()` can rebuild it without knowing which run created the row.
- **`position_start` / `position_end` are real indexed columns.** Page-range filtering is a first-class feature and the overlap test (`position_start <= :to AND position_end >= :from`) has to be an index scan, not JSON extraction.
- **An empty filter list means "match nothing".** `whereIn(col, [])` compiles to `0 = 1`, which is what an empty MCP allow-list must do. This was a real bug once: `!== []` guards turned "expose nothing" into "expose everything". Do not reintroduce them.
- **`cost_micros` is an integer.** These are summed across millions of rows in SQL, where float accumulation drifts.
- **Ordering is on `embedding <=> :q`, not on `1 - (...) DESC`.** Ordering on the derived score defeats the HNSW index.
- **Vectors are L2-normalised on write.** Cosine similarity then equals the dot product, which makes MMR a single pass instead of three.
- **The model is never called when retrieval is empty.** An LLM handed no context answers from its parameters. `AnswererTest > it never calls the model when there is no context` pins this.
- **`QueryLog`, not `Query`.** A model named `Query` collides with Eloquent's static `query()`; `QueryCitation::queryLog()` cannot be called `query()` either — that is a fatal error, not a warning.
- **`ChunkDiffer` parks ordinals at +1,000,000,000 before renumbering.** The `(document_id, ordinal)` unique index would otherwise collide mid-update.
- **A window that consumed everything left does not carry an overlap forward.** `windows()` returns as soon as the emitted window covers the whole buffer and the sentence stream is exhausted. Without that guard every document ended with a chunk whose sentences had all already been emitted — an extra embedding per document and a near-duplicate hit at retrieval; short documents were worst, a 40-token epigraph produced three chunks. Look there first if chunk counts jump.
- **Config is merged recursively, not by `mergeConfigFrom`.** Laravel's merges the top level only, so a published `config/rag.php` would have to repeat every nested default or silently lose it. `mergeRagConfig()` merges deep with list arrays replaced wholesale (`Support\Arr::mergeConfig`), which is what lets the host file carry only its overrides — and why `rag.sources`, a list, replaces rather than appends.
- **A filter's name is not its column.** Two filters routinely narrow the same column (`ids` and `id_range` both hit `id`); the name is what `--filter=` and the form state path address, so `FilterSet` keys on it.
- **`Filter::boolean(..., default: false)` constrains every run.** `FilterSet` skips only blank values, and `false` is not blank. That is deliberate: "exclude the bad rows unless asked" is one line, and the toggle in the form is what opts back in.
- **`FilamentAiServiceProvider` registers pgvector's Blueprint macros itself.** pgvector's own provider does it, but is not discovered under Testbench or with discovery disabled.
- **Component views live in `resources/views/components/`, not under `filament/`.** With the `rag` view namespace registered, Laravel resolves `<x-rag::chunk-card />` to `rag::components.chunk-card`. An earlier version called `Blade::anonymousComponentNamespace('filament.components', 'rag')`, which resolves against the *application's* view paths — so it worked only in the test suite, whose base case had put the package's view directory on those paths. That was a false green; the test now asserts the view resolves by namespace instead.
- **Nothing polls unless a run is in flight.** The host panel here runs `AuthenticateSession`, and several Livewire components refreshing at once can race it into regenerating the session, leaving the other in-flight requests with a stale CSRF token — which the browser reports as "Page Expired", and refreshing re-arms the pollers into a loop. `ragPollIntervalWhileRunning()` and `LatestRunsTable::pollInterval()` return null when no run is active; `rag.filament.poll_interval` set to null disables polling entirely.
- **The chat's assets are served by a route, not published.** `publishes()` puts a copy in `public/` that nothing updates when the package does, and the failure mode is a page silently rendering against last month's CSS. `AssetController` streams them from `resources/dist/` with a content hash in the URL and a year-long immutable cache, so it costs one request per deploy. The publish tag still exists for hosts that would rather serve them.
- **The chat cannot reuse `x-rag::chunk-card`.** That component renders `<x-filament::badge>`/`<x-filament::icon>` and leans on Tailwind classes that only exist because Filament's build safelists them. The chat is a separate document with no Filament and no host Tailwind, so its passage card is a plain-CSS twin. Two renderers is the cost of the page working without a panel.
- **Chat ability defaults must not assume a `Gate::before` super-admin bypass.** The host here deliberately has none — several of its checks legitimately fail and must keep failing — so every default in `ChatAbilities::DEFAULTS` answers on its own. `all_conversations` is the only one that defaults to false.
- **A permission-name string is resolved before a callable in `ChatAbilities::resolve()`.** `is_callable()` is true for any string naming a global function, so checking callables first would turn a permission called `viewRag` into a call to `viewRag()`.
- **`AnswerOptions::withRetrieval()` and `withChannel()` rebuild positionally.** A new constructor argument that is not added to both is dropped silently. `AnswerOptionsTest` pins `conversationId`/`turn` for exactly this reason.
- **The chat's `conversation_id` foreign key is skipped on SQLite.** SQLite cannot attach one to a table that already exists, and the test suite runs on SQLite. `Conversation`'s `deleting` hook deletes the turns itself, so a deleted thread never leaves orphaned answers on either driver.
- **The chat's front end is plain DOM code, not Alpine or Livewire.** Livewire would make the package depend on it; Alpine is not on the page outside a Filament panel. A few hundred lines of vanilla JS is what lets the page work in a host with no build step at all.
- **`PrismLanguageModel::stream()` reads `$event->delta`, not `$event->text`.** Prism streams typed events (`TextDeltaEvent`, `StreamEndEvent`, ...); older releases yielded chunks with `text`. Reading only one shape yields zero deltas, an empty accumulated answer, and therefore an answer citing nothing -- which `looksRefused()` correctly reports as a refusal. The symptom is *every* streamed question refusing while the non-streaming path answers fine, and nothing in the suite catches it because `FakeLanguageModel` yields plain strings. `PrismStreamEventTest` pins both shapes.
- **`LaravelAiLanguageModel` prompts a one-off `CompletionAgent`.** laravel/ai only prompts agents, and reads temperature and max tokens from class attributes *or* same-named methods. Config values cannot be attributes, so they are methods, and a null temperature must stay null (`LaravelAiDriversTest > it leaves a null temperature out of the request`). Its stream reads `TextDelta::$delta` and sums `StreamEnd` usage; the zero-deltas-means-every-streamed-answer-refuses trap above applies unchanged, which is why that test joins the deltas and compares them to the answer.
- **Every inline SVG on the chat page needs an explicit size.** An `<svg>` with a `viewBox` and no width/height is 300x150, not "as tall as the text". `.rag svg` sets the default once; a new icon in a new context inherits it instead of blowing up the layout.
- **The collapsed sidebar is one grid column, not a zero-width first one.** `.rag-sidebar` is `display: none` when closed, so it stops being a grid item and `.rag-main` slides into whatever the first track is. A `0` first track therefore squeezes the conversation to nothing.
- **The chat's CSS custom properties live on `:root`.** They were on `.rag` once, and `body` is its *ancestor*: custom properties inherit downwards only, so every `var()` on `body` was invalid and the whole page fell back to the browser's default serif.
- **A conversation id is checked with `Str::isUuid()` before it reaches the query.** On PostgreSQL `rag_conversations.uuid` is a native `uuid`, so `where('uuid', 'undefined')` is not a miss -- it is `SQLSTATE[22P02]` and a 500. SQLite stores it as text and swallows this, so the Web suite cannot catch it; the guard is the test.
- **An unusable conversation id opens a new thread, it does not fail the request.** The id is a continuation hint. A deleted, pruned or corrupted one must never leave somebody on a page that refuses to answer anything, so `AskRequest` does not validate it as a uuid and `AskController::resolveConversation()` falls back to a fresh conversation.
- **The `job_batches` migration is dated `9999_12_31`.** It must run *after* every host migration so an application that publishes its own always wins and ours no-ops. Dated normally it created the table first and made the host's migration fail with a duplicate-table error — which is exactly what happened in this repo.

## Testing notes

| Suite | Runs on |
|---|---|
| `Unit` | nothing — pure functions, no app boot |
| `Feature` | SQLite + `InMemoryVectorStore` + fake embedding/LLM |
| `Filament` | SQLite + a real Filament panel via `FilamentTestCase` |
| `Web` | SQLite + session + a logged-in user via `WebTestCase`, deliberately with **no** Filament |
| `Pgvector` | real PostgreSQL, skipped when unreachable |

- `McpToolsTest::seedForMcp()` shrinks `target_tokens` and `min_tokens` on purpose: its pages are two sentences long, and with the shipped 512-token target they land in one chunk spanning both pages, which would make the page-range assertion vacuous.
- The feature suite ingests `tests/Fixtures/TestBookSource` and, for the grouped shape, `TestTitleIndexSource`; `rag.sources` holds its class-string. `SourceRegistry` memoises what it resolved, so a test that rewrites `rag.sources` mid-case must call `SourceRegistry::flush()` — that is the only reason the method exists.
- `TestCase::defineEnvironment()` sets `rag.retrieval.min_score` to `0.0`. `FakeEmbeddingProvider` is deterministic but not semantic, so the score floor tuned for a real model would reject everything.
- `QueuedIngestionTest` uses the **database** queue driver and `drainQueue()`, not `sync`. The sync driver runs batch jobs inside `Batch::add()`, before the pending count settles, so completion callbacks never fire the way they do in production — which is precisely the behaviour under test.
- `getPackageProviders()` deliberately omits `PgvectorServiceProvider`: it ships a `CREATE EXTENSION` migration SQLite cannot run.
- `getPackageProviders()` lists `Laravel\Ai\AiServiceProvider` explicitly. Without it `CompletionAgent::fake()` and `Embeddings::fake()` have no manager to swap their gateways on.
- `rag:install` publishes `config/rag.php` into Testbench's skeleton under `vendor/`, which outlives the run. `CommandsTest` deletes it in `afterEach`: a stale copy wins the recursive config merge and silently overrides every later change to the defaults. After the namespace rename it pointed the normalizers at classes that no longer existed and failed 42 unrelated tests.
- Filament widgets use `protected ?string $pollingInterval` — **not** `static`. Redeclaring the parent's non-static property as static is a fatal error.
- Livewire component state must be scalars or arrays. `IngestKnowledge::$estimate` is an array for this reason, not the DTO the planner returns.

## Where to look when something is wrong

| Symptom | Look at |
|---|---|
| Search returns nothing | `rag:status` for stale vectors; `retrieval.min_score`; whether the model changed |
| Answers are ungrounded | `resources/views/prompts/system.blade.php`; `require_citations`; `QueryResource` for which citations went unused |
| Re-ingestion re-embeds everything | chunker determinism; `params_checksum`; whether a chunking parameter or the title changed |
| A run stalls at some percentage | is a worker consuming `rag.queue.queue`; `IngestionRun::failedJobs()`; run items with `status = failed` |
| Queries are slow | is there an ANN index (`rag:status` warns); `hnsw.ef_search`; are filters narrowing before ranking |
| Chunks span the wrong pages | `SentenceSplitter` bridging; `ChunkerBoundaryTest` |
| The chat page 500s for a guest | the host's login route is not *named* `login`; set `rag.chat.middleware` |
| A chat control is missing | its `rag.chat.<ability>`; remember `ChatPayload` omits denied values entirely |
| The chat answers but saves nothing | `rag.retrieval.log_queries`, and `rag.chat.abilities.history` |
| Every question errors, none reach the model | is the embedder reachable? `rag.embeddings` is called on *every query*, not just at ingestion |
| The chat streams nothing under Octane | a worker's `flush()` may never reach the client; set `rag.answering.stream` to false for one plain XHR instead |
| Every streamed question refuses | `PrismLanguageModel::stream()` and the shape of Prism's events; compare against the non-streaming path |

## Not built (deliberately)

- Only one vector driver. The `VectorStore` contract exists so a second is one class, not a refactor.
- No re-ranker model. MMR and de-duplication cover most of the benefit; a cross-encoder would be a new `Retriever`.
- No conversational memory beyond `AnswerOptions::$history`, which is passed straight to the prompt.
- `PruneOrphanChunksJob` exists but is not scheduled. Ingestion only walks what the source still returns, so it cannot notice deletions; schedule it nightly on a corpus that gets pruned. `RagManager::forget()` is the immediate counterpart, for a host that calls it from a model observer -- note that on a grouped source its `$externalId` is the *group*, so a host deleting one row of thousands must re-ingest that group instead.
