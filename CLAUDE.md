# CLAUDE.md — working on `murkrow/filament-ai`

Handoff notes for an AI agent continuing this package. Read `README.md` first for what it does; this file is about how it is built and what will bite you.

## Status

`main` carries the released package (5.0.0: laravel/ai ^1.0, Prism removed). Every push to `main` runs `release.yml`, which auto-tags the next *patch*: a breaking change must be tagged by hand (`vX.0.0`) with `[skip release]` in the commit. The `feat/filament-ai` branch is history. Green: ~396 tests on SQLite + 14 pgvector.

```bash
composer install
vendor/bin/pest                       # unit + feature on SQLite
vendor/bin/pest --testsuite=Pgvector  # needs a real PostgreSQL with pgvector
```

The pgvector suite skips itself when no database is reachable. Point it at one with
`FILAMENT_AI_TEST_PG_HOST` / `FILAMENT_AI_TEST_PG_PORT` (defaults: host `fai-test-pg`, port `5432`,
db `rag_test`, user/password `rag`/`rag`):

```bash
docker run -d --name fai-test-pg \
  -e POSTGRES_USER=rag -e POSTGRES_PASSWORD=rag -e POSTGRES_DB=rag_test \
  -p 55432:5432 pgvector/pgvector:pg17
FILAMENT_AI_TEST_PG_HOST=127.0.0.1 FILAMENT_AI_TEST_PG_PORT=55432 vendor/bin/pest --testsuite=Pgvector
```

CI (`.github/workflows/tests.yml`) runs the full suite, pgvector included, against a
`pgvector/pgvector:pg17` service on every push and pull request.

## The four invariants

Break any of these and the package stops being what it is.

**1. No host application classes.** Nothing under `src/` may name `App\Models\Book` or anything like it. Host models are reached only from a source class the *application* writes (`app/Knowledge/BookSource.php`, extending `EloquentSource`), listed as a class-string in `config/filament-ai.php` under `sources`. The test fixtures (`tests/Fixtures/TestBook.php`, `TestBookSource.php`) exist to prove this: if they are enough to exercise every path, the package is genuinely generic.

**2. Optional integrations stay optional, and laravel/ai stays contained.** Filament ^5 and `laravel/ai` are `require`d: the package is a Filament plugin and its agent layer is laravel/ai. `laravel/mcp` and Scout stay `require-dev` + `suggest`; every reference to them is behind `class_exists()` plus a config toggle, so a host without them boots normally and a breaking upgrade downstream degrades to "feature off" rather than a fatal error. laravel/ai's request, response, event and storage shapes are read only in `src/Llm/LaravelAi*`, `src/Embeddings/LaravelAi*` and `src/Agent/**` (the agent layer *is* laravel/ai). Controllers, Filament pages and `src/Chat/` never import `Laravel\Ai`: the stream is translated in `Agent/Chat/TurnStream`, stored turns in `Agent/Chat/ConversationTranscript`.

**3. Nothing touches the database during `register()`.** `migrate` has to run against a schema that does not exist yet. `SettingsRepository::available()` guards on `Schema::hasTable()` inside a try/catch for exactly this reason, and settings are applied in `$this->app->booted()`.

**4. The chunker is pure.** Same segments plus same options must always produce byte-identical text and hashes. `ChunkerBoundaryTest > it is deterministic across runs` pins this. If it ever fails, incremental re-ingestion silently becomes a full re-embed on every run — expensive and almost invisible.

## Shape of the thing

```
Source (class)   →  DocumentIngestor  →  Chunker  →  ChunkDiffer  →  ChunkEmbedder  →  VectorStore
                                                                                            ↓
                          Answerer  ←  PromptRenderer  ←  Retriever  ←────────────────────┘
```

- `src/Sources/` — `EloquentSource` (one row, one document) and `GroupedEloquentSource` (one *group* of rows, one document, for tables of thousands of short entries) are the abstract bases a host extends (model, `SegmentMap`, `PositionLabels`, `ChunkingOverrides`, filters); `ClosureKnowledgeSource` the escape hatch; `SourceRegistry` resolves the class-strings in `filament-ai.sources` through the container. Filters are objects (`src/Sources/Filters/`, built via `Filter::ids(...)`) that own their own `apply()`, and the *same* object drives `--filter=`, the ingestion query and the Filament form — which is why nothing in `src/Sources/` may import Filament: the field mapping lives in `Filament/Forms/SourceFilterSchema`, keyed by `instanceof`, and an unknown filter class degrades to a text input.
- `src/Chunking/` — `SentenceSplitter` produces page-tagged sentences (with OCR hard-splitting and cross-page bridging); `SlidingWindowChunker` slides a token-budgeted window over them. Read the comments there before changing anything: the `max(1, $cut)` in `windows()` is a monotonicity guard, not defensive noise.
- `src/Ingestion/` — `ChunkDiffer` is the reason re-indexing is cheap. `RunProgress` uses atomic `incrementEach`, never read-modify-write, because several workers report on the same run. `ChunkEmbedder` splits a job's group into `maxBatchSize()` calls but writes every vector in one upsert at the end: a group that fails half-way leaves nothing written, so the retry's progress accounting stays exact.
- `src/VectorStores/` — only `PgVectorStore` ships. `AbstractVectorStore::baseQuery()` owns filter compilation for every driver. `Contracts\\ResizableVectorStore` is a second, optional contract (installed column width, in-place resize) so `ai:vector:reindex` can repair a dimensions change without widening `VectorStore` for third-party drivers.
- `src/Retrieval/`, `src/Answering/` — pipeline and grounding.
- `src/Filament/` — the panel surface (Filament is required). `src/Mcp/` — the optional MCP surface.
- `src/Llm/`, `src/Embeddings/` — `laravel-ai` is the driver, `fake` is for tests.
- `src/Knowledge/KnowledgeSearch` — search and fetch over the corpus for an *external* caller, scoped to an allow-list it never widens, and per user through a source's optional `ScopesDocumentsToUser` (applied as a `constrain` closure on the vector query and on the fetch query; a throwing scope excludes that source). The MCP tools and the agent tools are both thin wrappers over it; change rendering or scoping here, not in a tool.
- `src/Agent/` — the panel agent. `PanelAssistant` (a laravel/ai agent, usable with no subclass) composes the knowledge tools and `ResourceToolRegistry`'s tools. A Filament resource opts in with `AgentResource` + `InteractsWithAgent`; `ResourceInspector` reads its table, form and labels into a `ResourceBlueprint`, and `ListRecordsTool` / `ViewRecordTool` act on that blueprint only. `CreateRecordTool` / `EditRecordTool` / `DeleteRecordTool` write through `FormPipeline` (the resource's own form, run by `Schema::getState()` on an `AgentFormHost`) and implement laravel/ai's `Approvable`; `FormFieldMapper` only *describes* the offered fields as arguments. `PanelScope` + `BootAssistantPanel` put every chat request in its panel and tenant.
- `src/Agent/Solving/`, `src/Jobs/{SolveAttemptJob,EvaluateWaveJob}.php`, `SolveRun`/`SolveAttempt` — the iterative mode: waves of parallel attempts, a verifier, and a budget that ends it. Shaped like ingestion on purpose (`Bus::batch` + a `finally` job, atomic counters through `SolveProgress`), because it has the same problems.
- `src/Agent/Sandbox/`, `src/Agent/Tools/RunCode.php` — code execution. `CodeSandbox` is the contract, `PistonSandbox` the shipped driver, `FakeSandbox` the one tests bind. The tool does what a sandbox cannot: refuse a language nobody allowed, cap what goes in and comes back, and log every snippet that ran.
- `src/Filament/Pages/AgentSettings.php`, `src/Agent/Resources/ResourcePolicies.php` — what the assistant may do, edited from the panel. The page writes `filament-ai.agent.*` through the same `SettingsRepository` as the knowledge settings; `ResourcePolicies` applies `filament-ai.agent.resources.overrides` on top of each resource's own `agentTools()`.
- `src/Agent/Chat/`, `src/Filament/Pages/AssistantChat.php` — the chat page. `ConversationTranscript` reads messages, tool outcomes and pending approvals from laravel/ai 1.0's `steps`/`status` columns; `TurnStream` translates the live stream; `ToolLabels` and `ApprovalCards` are what a person reads; `PageContext` turns the page on screen into a resource slug and record key for the topbar button; `AssistantAccess` is the on/off switch and the `filament-ai.agent.authorize` callback.
- `src/Chat/`, `src/Http/`, `routes/chat.php`, `resources/views/chat/`, `resources/dist/` — the standalone chat page and the transport of both chats. `ChatAbilities` is its whole authorization vocabulary (`debug` gates everything technical); `ChatPayload` and `TurnStream` are the only things that decide what reaches the browser.

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
- **Config is merged recursively, not by `mergeConfigFrom`.** Laravel's merges the top level only, so a published `config/filament-ai.php` would have to repeat every nested default or silently lose it. `mergeRagConfig()` merges deep with list arrays replaced wholesale (`Support\Arr::mergeConfig`), which is what lets the host file carry only its overrides — and why `filament-ai.sources`, a list, replaces rather than appends.
- **A filter's name is not its column.** Two filters routinely narrow the same column (`ids` and `id_range` both hit `id`); the name is what `--filter=` and the form state path address, so `FilterSet` keys on it.
- **`Filter::boolean(..., default: false)` constrains every run.** `FilterSet` skips only blank values, and `false` is not blank. That is deliberate: "exclude the bad rows unless asked" is one line, and the toggle in the form is what opts back in.
- **`FilamentAiServiceProvider` registers pgvector's Blueprint macros itself.** pgvector's own provider does it, but is not discovered under Testbench or with discovery disabled.
- **Component views live in `resources/views/components/`, not under `filament/`.** With the `rag` view namespace registered, Laravel resolves `<x-filament-ai::chunk-card />` to `filament-ai::components.chunk-card`. An earlier version called `Blade::anonymousComponentNamespace('filament.components', 'rag')`, which resolves against the *application's* view paths — so it worked only in the test suite, whose base case had put the package's view directory on those paths. That was a false green; the test now asserts the view resolves by namespace instead.
- **Nothing polls unless a run is in flight.** The host panel here runs `AuthenticateSession`, and several Livewire components refreshing at once can race it into regenerating the session, leaving the other in-flight requests with a stale CSRF token — which the browser reports as "Page Expired", and refreshing re-arms the pollers into a loop. `aiPollIntervalWhileRunning()` and `LatestRunsTable::pollInterval()` return null when no run is active; `filament-ai.filament.poll_interval` set to null disables polling entirely.
- **The chat's assets are served by a route, not published.** `publishes()` puts a copy in `public/` that nothing updates when the package does, and the failure mode is a page silently rendering against last month's CSS. `AssetController` streams them from `resources/dist/` with a content hash in the URL and a year-long immutable cache, so it costs one request per deploy. The publish tag still exists for hosts that would rather serve them.
- **Chat ability defaults must not assume a `Gate::before` super-admin bypass.** The host here deliberately has none — several of its checks legitimately fail and must keep failing — so every default in `ChatAbilities::DEFAULTS` answers on its own. `all_conversations` is the only one that defaults to false.
- **A permission-name string is resolved before a callable in `ChatAbilities::resolve()`.** `is_callable()` is true for any string naming a global function, so checking callables first would turn a permission called `viewRag` into a call to `viewRag()`.
- **The chat's front end is plain DOM code, not Alpine or Livewire.** Livewire would make the package depend on it; Alpine is not on the page outside a Filament panel. A few hundred lines of vanilla JS is what lets the page work in a host with no build step at all.
- **`LaravelAiLanguageModel` prompts a one-off `CompletionAgent`.** laravel/ai only prompts agents, and reads temperature and max tokens from class attributes *or* same-named methods. Config values cannot be attributes, so they are methods, and a null temperature must stay null (`LaravelAiDriversTest > it leaves a null temperature out of the request`). Its stream reads `TextDelta::$delta` and sums `StreamEnd` usage; the zero-deltas-means-every-streamed-answer-refuses trap above applies unchanged, which is why that test joins the deltas and compares them to the answer.
- **Every inline SVG on the chat page needs an explicit size.** An `<svg>` with a `viewBox` and no width/height is 300x150, not "as tall as the text". `.rag svg` sets the default once; a new icon in a new context inherits it instead of blowing up the layout.
- **The collapsed sidebar is one grid column, not a zero-width first one.** `.fai-sidebar` is `display: none` when closed, so it stops being a grid item and `.fai-main` slides into whatever the first track is. A `0` first track therefore squeezes the conversation to nothing.
- **The chat's CSS custom properties live on `:root`.** They were on `.rag` once, and `body` is its *ancestor*: custom properties inherit downwards only, so every `var()` on `body` was invalid and the whole page fell back to the browser's default serif.
- **Agent tools return `Error: ...` strings instead of throwing.** A thrown exception ends the model's whole turn; an error it can read lets it fix its arguments or explain the problem. `KnowledgeResult::toToolOutput()` is the one place the prefix is added.
- **The list tool exposes the table's filters, and Filament applies them.** `TableFilterMapper` maps select-shaped filters (`SelectFilter`, `TernaryFilter`, anything extending them) to arguments, and `ListRecordsTool` hands the value straight back to `$filter->apply()`, so a relationship, a scope or a custom query narrows exactly as it does in the table instead of being re-implemented as SQL. A custom `Filter::query()` needs form state the agent does not have and is left out; a filter that throws returns an error, because unfiltered rows would be reported as filtered. Without this the tool could only match text: the demo answered "0 attività aperte" with 14 open, the model having searched the word in the titles.
- **Resource tools check policies inside `handle()`, not only at registration.** `ResourceToolRegistry` leaves out a resource whose `viewAny` denies the user, so the model is not told about it, but the tool list is built once per prompt and a conversation can outlive a permission change. `AgentResourceToolsTest > it checks the policy again when a tool is called` pins it. `view` also checks the record-level `view` policy.
- **Resource tools query through `Resource::getEloquentQuery()`.** That is what applies tenant scoping and whatever the host narrowed for its table; a record outside it is reported as not found, not as forbidden, so its existence does not leak.
- **`RecordPresenter` skips attributes the model hides, even when named.** A resource declaring a column is not consent to hand a password hash to a language model.
- **`ResourceInspector` builds the table and the form against an unmounted instance of one of the resource's own pages.** `Table::make()` needs a `HasTable`, and `Schema::make()` with no component throws a `TypeError` from `getLivewire()` the moment its components are read; no Livewire component is mounted when the agent runs. Every read is wrapped so an exotic resource degrades to a less informed tool rather than an exception -- which is also how that `TypeError` once went unnoticed: the form read failed silently and every write tool vanished. Search columns are intersected with the model table's real columns: a searchable relationship or custom-query column would otherwise reach SQL as a column that does not exist.
- **Writes are on by default, deletion is not, and every write asks first.** An opted-in resource gets create and edit, each approved by the user unless the resource calls `withoutApproval()`. Delete must be requested with `with(AgentTools::DELETE)`, `withoutApproval()` refuses it, and `ResourceBlueprint::requiresApproval()` returns true for it unconditionally.
- **Writes run the resource's form, not a copy of its rules.** `FormPipeline` builds the form on `AgentFormHost` exactly as `CreateRecord`/`EditRecord::defaultForm()` do (operation, model or record, `data` state path), fills it, overlays the arguments and calls `getState()` -- visibility, disabled, dehydration, every rule, relationship layouts. An earlier version validated hand-collected rules against an unmounted list page with no operation: hidden and `disabledOn('edit')` fields were writable and a throwing rule closure dropped every rule of its field. Only root-level plain fields are taken from the arguments; password fields never. Page hooks do not run; `agentMutateBefore{Create,Save}()` on the resource do. `AgentFormPipelineTest` pins each behaviour.
- **A write the form would refuse is never put to the user.** `needsApproval()` runs a `preview()`; if it fails the call is not gated and `handle()` -- remembering the refusal by argument fingerprint -- returns the errors without writing, even if the data changed in between. On resume laravel/ai calls `shouldRequestApproval()` again, so the same holds there.
- **Tenancy is the panel's, so the panel must be current.** Filament registers the tenant scope and the `creating` observer in `Panel::boot()` and applies them only while that panel is current with a tenant set. The chat routes are outside the panel: `BootAssistantPanel` does what `SetUpPanel` + `IdentifyTenant` do, from the `panel`/`tenant` the page sends. A named tenant the user cannot access is refused, never swapped for another; no tenant on a tenant panel means no resource tools (`ResourceToolRegistry::blueprints()` asks `PanelScope::allowsResources()`). `AgentTenancyTest` has a real tenant panel.
- **Decisions are serialised per conversation.** laravel/ai stores an approved call's result only after running it, so two decisions reading "pending" both ran the write. `AssistantController` takes `Cache::lock('filament-ai:turn:{id}')` before reading what is pending and releases it when the stream ends.
- **Tools never throw.** `GuardsToolFailures::guarded()` reports the exception with a reference and returns an `Error:` string: laravel/ai would otherwise send the exception message (SQL, values) to the provider and store it.
- **The settings page can add approval, never remove it.** `ResourcePolicies::apply()` intersects the stored `unapproved` list with what the code declared; an empty list asks about every write again.
- **`PanelAssistant::provider()` and `model()` are what make `filament-ai.llm.*` reach the agent.** laravel/ai asks an agent for those two methods before falling back to `config('ai.default')`, and `LaravelAiLanguageModel` is the *retrieval* pipeline's model, never the agent's. Without them a host with `FILAMENT_AI_LLM_PROVIDER=anthropic` had its panel agent call OpenAI with an empty key and get a 401 -- which is exactly how it was found, in the demo app. `AssistantProviderTest` fakes the gateway on one named provider, so a regression resolves elsewhere and fails.
- **Blade compiles no directives inside a component tag's attributes.** `wire:click="decide(@js($id), true)"` on an `<x-filament::button>` reaches the browser verbatim, and Livewire refuses the expression with "illegal character U+0040" — the `@`. Plain HTML elements are fine, which is why the history buttons still use `@js()`. The approval buttons interpolate a sanitised id instead, and `AssistantChatTest > it renders approval buttons the browser can actually run` asserts on the rendered HTML, because every server-side test passed while the buttons were dead in the browser.
- **A wave's attempts never see each other; only waves carry feedback.** Four attempts told about each other converge on one answer, which wastes three model calls. They are spread apart by temperature instead (`PanelAssistant::withTemperature()`), and what the *previous wave* got wrong is prepended to the next prompt.
- **`Exhausted` is not `Failed`.** The run tried within its budget and nothing passed: the best attempt, its score and the judge's reason are kept and turned into a sentence for the user. Treating it as a failure would throw away the only thing the run produced.
- **An unjudged attempt is not a rejected one.** `JudgeVerifier` never throws: a judge that errors returns `Verdict::unjudged()`, the attempt keeps its answer and stays out of the ranking. Counting it as rejected would let one broken judgement discard a good answer.
- **Every budget is checked in one place, `EvaluateWaveJob::reasonToStop()`.** Waves, tokens, cost and seconds, whichever runs out first. Scattering those checks is how a loop becomes unstoppable.
- **`SolveRun::durationSeconds()` casts to int.** Carbon 3 answers in float seconds, and the return type is `?int`: without the cast the budget check threw a `TypeError` inside the queued evaluation, leaving the run stuck at *running* with no error anywhere near it. Found by a two-wave test; single-wave runs never reached the check.
- **The sandbox is a separate, privileged container -- never the application.** Piston isolates each submission with `isolate(1)`, which needs cgroup and mount privileges: that privilege belongs to the `piston` service, whose port is not published, and the app only speaks HTTP to it. Giving the app the Docker socket instead would have been root on the host. Verified from inside the sandbox in the demo: no route to the application, no route to the internet, none of its files, no environment variables carrying keys.
- **Piston refuses a `run_timeout` above its own ceiling, 3s by default.** It answers 400 with a message rather than clamping, which the driver surfaces as an error the model can read; `PISTON_RUN_TIMEOUT` raises the ceiling and `filament-ai.agent.sandbox.timeout` must stay under it. Same shape for output: Piston's own 1KB cap truncates before the application gets to choose what to keep.
- **Code execution is off unless a host switches it on, and every run is written to the log with its snippet.** "The agent computed it" is not an audit trail. `filament-ai.agent.sandbox.enabled` gates the tool and `RunCode::enabled()` is what `PanelAssistant` asks before offering it.
- **`maxSteps()` is what makes a sandbox usable.** Write a program, run it, read the error, fix it: four round trips before an answer. `filament-ai.agent.max_steps` feeds `PanelAssistant::maxSteps()`; null leaves laravel/ai's own default, which is lower.
- **The sandbox's url and driver are not editable from the panel.** Everything else about it is -- on/off, languages, time limit, output caps -- but a form that decides where the application posts code is an SSRF, not a setting, and whoever administers a panel is not necessarily whoever controls the network. `filament-ai.settings.overridable` says so in a comment next to the keys that *are* there.
- **The language picker offers installed runtimes plus whatever config already names.** Options come from `ListsRuntimes` (optional second contract, like `ResizableVectorStore`), cached for a minute so an unreachable sandbox does not hold the page for the HTTP timeout. A configured language the sandbox lacks is kept and marked rather than dropped: dropping it made the stored value invalid the moment the sandbox went down, and Filament then refused to save the whole form -- which is how four unrelated settings tests went red at once.
- **The settings page can only take away.** Abilities are intersected with what the resource's `agentTools()` declared, a resource that never implemented `AgentResource` cannot be added, and `ResourcePolicies` filters `delete` out of the "without approval" list before `AgentTools::withoutApproval()` would throw on it. What matches the code exactly is stored as *nothing*, so changing `agentTools()` later is picked up rather than shadowed by a stale row.
- **`KnowledgeSettings` fills and saves only the keys it owns.** It renders no `agent.*` field, but it used to `fill()` every effective key: the agent's keys ended up in its Livewire state and its `save()` wrote them straight back, per-resource policies included. `owns()` now gates mount, save and reset, and `AgentSettingsPageTest > it keeps the agent keys out of the knowledge settings form` pins it.
- **The chat page keeps no conversation state of its own.** Messages and pending approvals are read from laravel/ai's store on every render, so a reload, a second tab and a turn paused for approval all show the same thing.
- **`AssistantChat`'s URL properties are hints, not facts.** `conversationId`, `contextResource` and `contextRecord` come from the query string and the browser can rewrite them; every action re-checks that the conversation belongs to the user (`ConversationTranscript::owns()`) and resolves the record through the resource's query and `view` policy. `AssistantChatTest > it will not open a conversation that belongs to someone else` pins the first.
- **A new chat message is ignored while a change awaits a decision.** laravel/ai resumes from the latest stored turn, and a message sent on top would bury the paused call. Only the latest assistant turn's pending calls count, so an old pause cannot block a thread forever.
- **The `job_batches` migration is dated `9999_12_31`.** It must run *after* every host migration so an application that publishes its own always wins and ours no-ops. Dated normally it created the table first and made the host's migration fail with a duplicate-table error — which is exactly what happened in this repo.

### One chat, two doors

- The standalone page and `AssistantChat` render the same Blade component (`resources/views/components/chat.blade.php`) and the same framework-free `resources/dist/fai-chat.{js,css}`. Do not add Livewire or Filament components to it: it must run on a page with no panel. `embedded` only swaps CSS custom properties and hides the panel-duplicated chrome.
- Filament v4+/v5 colour tokens (`--gray-200`, `--primary-600`) are **complete colours in oklch**, not RGB triplets. `rgb(var(--gray-200))` is invalid CSS and paints nothing, silently. Use `var(--gray-200, #fallback)` and `color-mix()` for alpha.
- One mode, one store: every thread lives in laravel/ai's tables, the only place an approval pause can resume from. `rag_conversations` is legacy and no longer written by the chat.
- The routes in `routes/chat.php` are the transport for both surfaces, so they stay registered while either chat is on. `filament-ai.chat.enabled` gates only the page (`ChatController::page()`), checked at request time because routes are bound at boot.
- The payload carries only the context `AssistantTurn::resolvedContext()` accepted -- never the record key the browser sent.
- laravel/ai 1.0 stores each step with its own results, which fixed the 0.11 replay of a paused multi-step turn; `ReplaySafeConversationStore` is gone and `AgentHardeningTest > it resumes a turn that read before it paused on a write` pins the fix.
- **What a person sees vs the `debug` ability.** Everyone gets labels ("Search customers"), approval cards with before/after values, and failures as a sentence. Tool names, arguments, raw errors, model, tokens, scores, the solve-run link and setup hints are added only for `debug`, in `TurnStream`, `ApprovalCards`, `ChatPayload::presentMessages()` and `AssistantController` -- never hidden client-side.
- Every chat request carries `?panel=&tenant=` from `payload.scope` (`scoped()` in the JS).
- `filament-ai-chat.js`'s Markdown renderer reads block structure line by line (headings from h3, nested lists, GFM tables, quotes, rules, fences) and only then applies inline formatting, so a list's `*` is never taken for italics. It escapes first and only produces its own tags; links are limited to `http(s)://` and same-site `/path` (not `//host`). Cases live in `tests/js/markdown.cjs` (run by `ChatMarkdownTest` when node exists). Filament's base styles strip list bullets and heading sizes, so every element it produces is styled explicitly in `filament-ai-chat.css`.

### Solving strategies

- The engine (waves, budgets, judging) is the package's; the method is a `SolveStrategy` in the application. `DefaultStrategy::promptFor()` is byte-for-byte the prompt runs had before the contract, and a test pins it.
- The strategy class is stored on the run and re-resolved in every job (`Strategies::for()`), falling back to the default if the class is gone. Strategies must be stateless.
- A phase's `tools` only ever narrows (`PanelAssistant::onlyTools()`); a phase's `phases()` count overrides the configured `max_waves` but an explicit `SolveOptions::maxWaves` wins over both. Use `Strategies::plannedAttempts()` for any "N attempts" shown to a user -- phases can differ in size.
- The chat's "Keep trying" stream follows a queued run by polling `SolveRun`; it cannot be tested with a worker beside the request, so `SolveFromChatTest` swaps the `Solver` for one returning a finished run.

## Testing notes

| Suite | Runs on |
|---|---|
| `Unit` | nothing — pure functions, no app boot |
| `Feature` | SQLite + `InMemoryVectorStore` + fake embedding/LLM |
| `Filament` | SQLite + a real Filament panel via `FilamentTestCase` |
| `Pgvector` | real PostgreSQL, skipped when unreachable |

- `McpToolsTest::seedForMcp()` shrinks `target_tokens` and `min_tokens` on purpose: its pages are two sentences long, and with the shipped 512-token target they land in one chunk spanning both pages, which would make the page-range assertion vacuous.
- The feature suite ingests `tests/Fixtures/TestBookSource` and, for the grouped shape, `TestTitleIndexSource`; `filament-ai.sources` holds its class-string. `SourceRegistry` memoises what it resolved, so a test that rewrites `filament-ai.sources` mid-case must call `SourceRegistry::flush()` — that is the only reason the method exists.
- `TestCase::defineEnvironment()` sets `filament-ai.retrieval.min_score` to `0.0`. `FakeEmbeddingProvider` is deterministic but not semantic, so the score floor tuned for a real model would reject everything.
- `QueuedIngestionTest` uses the **database** queue driver and `drainQueue()`, not `sync`. The sync driver runs batch jobs inside `Batch::add()`, before the pending count settles, so completion callbacks never fire the way they do in production — which is precisely the behaviour under test.
- `getPackageProviders()` deliberately omits `PgvectorServiceProvider`: it ships a `CREATE EXTENSION` migration SQLite cannot run.
- The Filament suite's user is `Fixtures\TestUser` (a `FilamentUser` with `HasTenants`): without `canAccessPanel()` the assistant has no resource tools outside local. `TestTenantPanelProvider` is a second panel with tenancy (`TestTeam`/`TestTask`). `TestArticleResource` is *not* on any panel -- it exercises form behaviours directly.
- `AgentApprovalFlowTest` and `AssistantChatTest` mount a `FakeTextGateway` on `Ai::textProvider()` instead of calling `PanelAssistant::fake()`. laravel/ai skips approval resumption for a faked agent (`ResumesToolApprovals::resumesAgainstRealGateway()`), so a faked agent answers the decision without running the tool: the rejection case passes vacuously and the approval case can never pass.
- `getPackageProviders()` lists `Laravel\Ai\AiServiceProvider` explicitly. Without it `CompletionAgent::fake()` and `Embeddings::fake()` have no manager to swap their gateways on.
- `ai:install` publishes `config/filament-ai.php` into Testbench's skeleton under `vendor/`, which outlives the run. `CommandsTest` deletes it in `afterEach`: a stale copy wins the recursive config merge and silently overrides every later change to the defaults. After the namespace rename it pointed the normalizers at classes that no longer existed and failed 42 unrelated tests.
- Filament widgets use `protected ?string $pollingInterval` — **not** `static`. Redeclaring the parent's non-static property as static is a fatal error.
- Livewire component state must be scalars or arrays. `IngestKnowledge::$estimate` is an array for this reason, not the DTO the planner returns.

## Where to look when something is wrong

| Symptom | Look at |
|---|---|
| Search returns nothing | `ai:status` for stale vectors; `retrieval.min_score`; whether the model changed |
| Answers are ungrounded | `resources/views/prompts/system.blade.php`; `require_citations`; `QueryResource` for which citations went unused |
| Re-ingestion re-embeds everything | chunker determinism; `params_checksum`; whether a chunking parameter or the title changed |
| A run stalls at some percentage | is a worker consuming `filament-ai.queue.queue`; `IngestionRun::failedJobs()`; run items with `status = failed` |
| Queries are slow | is there an ANN index (`ai:status` warns); `hnsw.ef_search`; are filters narrowing before ranking |
| Chunks span the wrong pages | `SentenceSplitter` bridging; `ChunkerBoundaryTest` |
| The chat page 500s for a guest | the host's login route is not *named* `login`; set `filament-ai.chat.middleware` |
| A chat control is missing | its `filament-ai.chat.<ability>`; remember `ChatPayload` omits denied values entirely |
| Every question errors, none reach the model | is the embedder reachable? `filament-ai.embeddings` is called on *every query*, not just at ingestion |
| The chat streams nothing under Octane | a worker's or proxy's buffering; `X-Accel-Buffering: no` is sent, check the server |
| Every streamed question refuses | `LaravelAiLanguageModel::stream()` and laravel/ai's `TextDelta`; compare against the non-streaming path |
| The assistant has no resource tools | the page's `panel`/`tenant`; `FilamentUser::canAccessPanel()`; a tenant the user may access (`PanelScope`) |
| A field the model sets is "not saved" | it is hidden, disabled or not dehydrated for that user and operation, a password, or not `$fillable` |
| The panel chat is unstyled or colourless | `filament-ai-chat.css` loaded? then `[data-fai-embedded]` tokens: `rgb(var(--x))` around an oklch token paints nothing |
| "The assistant is not available" | laravel/ai's tables missing or not backfilled to 1.0 (`steps`/`status` columns); the `debug` ability shows the hint |
| The "Keep trying" toggle is missing | `filament-ai.agent.solving.enabled`, the `solve` ability, and laravel/ai's tables (`AssistantTurn::available()`) |
| A solve from the chat never finishes | is a worker consuming the solving queue? the page gives up after `max_seconds` + 60s; the run carries on |

## Not built (deliberately)

- Only one vector driver. The `VectorStore` contract exists so a second is one class, not a refactor.
- No re-ranker model. MMR and de-duplication cover most of the benefit; a cross-encoder would be a new `Retriever`.
- No conversational memory beyond `AnswerOptions::$history`, which is passed straight to the prompt.
- `PruneOrphanChunksJob` exists but is not scheduled. Ingestion only walks what the source still returns, so it cannot notice deletions; schedule it nightly on a corpus that gets pruned. `FilamentAiManager::forget()` is the immediate counterpart, for a host that calls it from a model observer -- note that on a grouped source its `$externalId` is the *group*, so a host deleting one row of thousands must re-ingest that group instead.
