# Changelog

Notable changes to `murkrow/filament-ai`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[semantic versioning](https://semver.org/spec/v2.0.0.html).

Versions before 2.0.0 were released under the package's former name,
`murkrow/laravel-rag`, which is abandoned in favour of this one.

## [Unreleased]

## [5.3.0] - 2026-09-30

### Added

- **`serper` web search driver** (`FILAMENT_AI_AGENT_WEB_SEARCH_DRIVER=serper`,
  `FILAMENT_AI_SERPER_API_KEY`): Google results through serper.dev, for new
  setups now that Google's Custom Search JSON API takes no new customers and
  shuts down on 2027-01-01. `FILAMENT_AI_SERPER_COUNTRY` / `_LANGUAGE` pin the
  results page's country and language; Google's answer box, when present,
  comes first. Cached like the Google driver; the key travels in a header.

## [5.2.2] - 2026-09-30

### Fixed

- A number in a question or in one of its rewrites ("1754") broke the
  assistant's search with `mb_strlen(): Argument #1 ($string) must be of type
  string, int given`: query words were collected as array keys, and PHP turns
  numeric keys into integers. They are strings again.

## [5.2.1] - 2026-09-30

### Fixed

- **The reranker read the wrong part of each passage.** It was shown the
  first 700 characters of a chunk; when the answer sat at the end (a story's
  opening, then the instruction that answers the question) every passage was
  graded 0. Rerankers now get the excerpt where the question's words -- and
  its rewrites' -- cluster (`Support\Excerpt`, shared with the agent's result
  excerpts). On the 45-question set: MRR 0.686 -> 0.770, recall@1 0.62 -> 0.71.
- A reranker that grades every passage the same no longer overwrites the
  fused order's scores with that constant; the fused order stands.
- The LLM reranker's prompt asks for graded, relative scores (same story or
  place 6-8, same subject 2-4) instead of all-or-nothing.

### Changed

- `retrieval.rerank.llm_max_chars` 700 -> 1600, now only a safety cap.

## [5.2.0] - 2026-09-29

Retrieval that finds paraphrased and riddle-like questions. Measured on a
45-question set over a 105k-chunk corpus: recall@10 0.60 -> 0.82, MRR
0.447 -> 0.686 (see `ai:eval`).

### Added

- **Keyword leg, on by default on Postgres** (`retrieval.hybrid.driver =
  tsvector`). A `content_tsv` column kept by a trigger, GIN-indexed, accents
  folded through `unaccent` when the extension can be created. Every query
  word is an OR'ed term weighted by rarity (IDF from the new `rag_lexemes`
  table); words in more than 8% of chunks are left to the vector leg; past
  `hybrid.timeout_ms` (3000) the vector leg answers alone. The driver is
  unavailable until the column exists, so it never falls back to a scan.
- `ai:fulltext`: installs the column and fills it in batches without locking
  (`--rebuild`, `--batch`, `--drop`). The migration fills it inline only up
  to 20k chunks; larger corpora run the command once.
- Word counts are recounted by `RefreshLexicalStatisticsJob`, queued (unique,
  delayed by `hybrid.statistics_delay`) after an ingestion changes chunks.
- **Query expansion** (`retrieval.expansion`, `FILAMENT_AI_QUERY_EXPANSION`,
  off by default): one cached model call rewrites the question into the words
  a source would use -- decoded periphrases, synonyms including archaic and
  dialect forms, a passage in the source's voice -- and each rewrite is
  retrieved and fused. `expansion.hint` describes the collection.
- **Reranking** (`retrieval.rerank.driver`, `FILAMENT_AI_RERANK_DRIVER`, off
  by default): `llm` grades the top candidates in one call; `ollama` judges
  each passage with a local model from yes/no log-probabilities (needs room
  for it beside the embedder). A failing reranker keeps the fused order.
- `ai:eval {file}`: recall@k and MRR per category on questions with known
  answers, with `--hybrid`, `--expand`, `--rerank` to compare setups.
- `search_knowledge` takes `exact` to skip the rewriting; its description
  and the assistant's rules now say how to search for a paraphrased question.
  `PanelAssistant::searchStrategy()` lets an application add its own advice.
- `agent.knowledge.passage_characters`: search results cut to the window
  where the query's words cluster; `fetch_document` still reads everything.

### Fixed

- Hybrid retrieval dropped every result: fused RRF scores (~0.01) went
  through the cosine `min_score` floor. The floor now applies to the vector
  leg only, lexical-only hits are loaded instead of discarded, and MMR weighs
  a 0-1 relevance instead of raw RRF scores.
- The Postgres lexical search ANDed every word and ranked without rarity.

### Changed

- `retrieval.fetch_k` 40 -> 60.

## [5.1.1] - 2026-09-24

### Fixed

- **The assistant stays in the application's language.** Some models --
  DeepSeek notably -- drifted into their own dominant language after a few
  tool round trips with English or code output, mid-answer and in the notes
  between tool calls. The system prompt now names the application's language
  (`filament-ai.agent.language`, falling back to `answering.language`, then
  the app locale) and says no document or tool output changes it.

### Added

- `filament-ai.agent.language` (`FILAMENT_AI_AGENT_LANGUAGE`).
- README: how the assistant's system prompt is assembled and how to change it
  per application.

## [5.1.0] - 2026-09-24

### Added

- **Assistant conversations** in the panel (`/ai/conversations`): every
  conversation anyone had with the assistant, read-only and gated like the
  other knowledge pages. The list shows who, when, how many messages and
  whether a turn failed, filterable by user and by failures; a conversation
  opens turn by turn with the answer rendered, each tool call with its
  arguments and result, the tokens used and the error of a failed turn.
  The question log (`/ai/queries`) only ever held the knowledge base's own
  answers, so assistant conversations had nowhere to be read.

## [5.0.2] - 2026-09-24

### Fixed

- **The assistant no longer claims abilities it does not have.** Its rules
  always described changing records, even with no write tool -- or no record
  tool at all -- so it offered to delete records and asked the user to confirm.
  The rules and the list of capabilities are now written from the tools the
  turn actually has, and say plainly when records cannot be read or changed.

### Added

- `agent.resources.writes` (`FILAMENT_AI_AGENT_WRITES`, also on the Assistant
  settings page): off makes the assistant read-only -- no create, edit or
  delete tool for any resource.
- A page opened before a deploy notices that the server now ships a
  different version of the chat and asks to be reloaded, instead of running
  the old script against the new endpoints (steps without names, odd errors).

## [5.0.1] - 2026-09-24

### Fixed

- **The assistant's answers render their Markdown.** Headings, tables, block
  quotes, rules and nested lists were shown as raw text, a bullet whose text
  contained emphasis lost its bullet, and inside a Filament panel lists had no
  bullets at all -- Filament's base styles remove them and the chat did not put
  them back.

### Security

- `fetch_web_page` refuses hosts written as non-canonical numbers
  (`0177.0.0.1`, `0x7f.0.0.1`, `127.1`). A resolver could answer one address
  for them while curl, reading octal, connected to another -- loopback.

### Added

- **Approve all / Reject all** on an approval with several pending changes.
  Each change keeps its own card; a card already decided keeps its answer.

## [5.0.0] - 2026-09-24

The assistant's writes, its tenancy and what the chat shows were reviewed for
production use. Several of the fixes below close holes rather than add
features; upgrade even if nothing else here matters to you.

### Security

- **The assistant acts inside its panel and tenant.** The chat's routes live
  outside the panel, so no panel was current and no tenant was set: Filament's
  tenant scope and its `creating` observer were inactive, and on a tenant panel
  the resource tools read, changed and deleted every tenant's records, and
  created records with no tenant. Every chat request now names its panel and
  tenant; both are checked the way Filament's own middleware checks them
  (`BootAssistantPanel`, `PanelScope`).
- **Resource tools need someone who could open the panel.** A user who fails
  `FilamentUser::canAccessPanel()` -- or a tenant panel without a tenant the
  user may access -- gets no resource tools, where before the standalone chat
  offered them to any signed-in user.
- **Writes go through the resource's form.** Create and edit used to validate
  a hand-built list of rules and save the raw arguments. Hidden and
  `visibleOn`/`hiddenOn` fields were writable, `disabledOn('edit')` fields were
  editable, `dehydrateStateUsing()` (password hashing) and `dehydrated(false)`
  were ignored, a Select's options and a relationship Select's scoped query
  were not enforced, a rule closure that threw dropped every rule of its field,
  and fields inside a `->relationship()` layout were written onto the parent.
  `FormPipeline` now runs `Schema::getState()` for the operation, exactly as
  the create and edit pages do.
- **Password fields are never offered to the model.**
- **An approved write runs once.** Two decisions arriving together -- a double
  click, a second tab -- both ran the write; decisions are now serialised with
  a per-conversation lock.
- **The settings page can no longer make a write silent.** It could turn off
  the approval of any create or edit; it can now only send a write the code
  already runs unasked back to asking. A deletion always asks, even when
  `withoutApproval()` is called on the tool itself.
- **Tool exceptions no longer reach the provider.** laravel/ai sends a thrown
  exception's message -- SQL and bound values included -- to the model and
  stores it with the conversation. Every tool now reports the exception with a
  reference and gives the model a plain error.
- **Related models' hidden attributes stay hidden** (`customer.api_token`), and
  `$visible` is honoured.
- **Web search:** `fetch_web_page` pins the address it checked (no DNS
  rebinding), blocks carrier-grade NAT, NAT64, 6to4 and IPv4-mapped addresses,
  streams the body with a hard size cap, and is **off by default**. The Google
  key travels in a header, not the url. Pages and snippets are marked as
  untrusted content, and every search and fetch is logged.

### Added

- Approval cards show the record and each field's current and new value, in
  the form's labels, with option labels, localised yes/no and dates.
- Steps are shown in words ("Search customers") instead of tool names.
- The `debug` chat ability: raw errors, tool names and arguments, model,
  tokens, cost, retrieval scores, the solve-run link and setup hints. Everyone
  else gets a sentence they can act on.
- `agentMutateBeforeCreate()` / `agentMutateBeforeSave()` on a resource, for
  what its pages' `mutateFormDataBefore*()` do that the agent must do too.
- `ScopesDocumentsToUser`: a knowledge source can narrow its documents per
  user; searches and reads apply it, and a scope that throws leaves the source
  out.
- Web search settings on the `Assistant settings` page.
- `AgentTools::requireApproval()`.
- Config: `chat.panel` (`FILAMENT_AI_CHAT_PANEL`, the panel the standalone
  page acts in), `agent.chat.turn_lock_seconds`, and `agent.temperature`
  (`FILAMENT_AI_AGENT_TEMPERATURE`), which the code already read without it
  being declared. A test now fails on any key read but not declared.

### Changed

- **Breaking: laravel/ai ^1.0.** Conversations are read from its new `steps` /
  `status` schema; a turn that failed is shown as failed.
- **Breaking: Prism is removed**, with the `prism` drivers and the
  `*.prism_provider` keys. Use the `laravel-ai` drivers.
- **Breaking: chat abilities.** `cost` is off by default; `debug` is new and
  off; `sources`, `passages`, `advanced`, `feedback` and `all_conversations`,
  which controlled nothing, are gone.
- **Breaking: overrides of `unapproved` in `agent.resources.overrides`** are
  intersected with what the resource declares (see Security).
- Solving attempts run in the panel, tenant and user that started the run, and
  never get write tools.
- Search in the list tool escapes `%` and `_`, casts columns on PostgreSQL and
  is capped at 200 characters; ids are checked against the key type before
  they reach SQL; list pages eager-load the relations they show and are capped
  at 200 records.

### Removed

- `ReplaySafeConversationStore`: laravel/ai 1.0 replays paused multi-step turns
  correctly. A test pins it.
- Config keys nothing read: `answering.stream`, `chat.history_turns`,
  `chat.max_conversations`, `retrieval.log_retention_days`,
  `mcp.server.version`.

### Fixed

- The `decide_first` and `nothing_pending` messages had no translation, so the
  chat showed their keys.
- User-facing messages still named `rag.*` configuration keys.
- Retrieval scores are rounded the same live and after a reload.

### Upgrading from 4.x

1. `composer require murkrow/filament-ai:^5.0 laravel/ai:^1.0` (add
   `aws/aws-sdk-php` if you use Bedrock).
2. Run laravel/ai's **backfill migration** for existing conversation tables
   (see its [upgrade guide](https://github.com/laravel/ai/blob/1.x/UPGRADE.md)),
   then `php artisan migrate` for this package's new `scope` column on solve runs.
3. If you used `FILAMENT_AI_LLM_DRIVER=prism` or `FILAMENT_AI_EMBEDDING_DRIVER=prism`,
   switch to `laravel-ai`.
4. Grant `filament-ai.chat.debug` (and `cost`, if wanted) to administrators.
5. Make sure your User model implements `FilamentUser` in production, as
   Filament itself requires; without it the assistant has no resource tools.
6. If a resource's create or edit page mutates data in `mutateFormDataBefore*()`
   (an owner, a slug), add the matching `agentMutateBefore*()`.
7. Set `FILAMENT_AI_AGENT_WEB_FETCH=true` if you relied on page fetching.
8. Drop removed keys from a published `config/filament-ai.php`.

## [4.1.0] - 2026-09-21

### Added

- `ai:status` names any `RAG_*` variable still in the environment and prints
  what it should be called now. A renamed variable does not fail -- it is
  simply not read, and the package falls back to its own defaults, which is
  how an application configured for DeepSeek spent a day answering 401 from
  OpenAI.

## [4.0.2] - 2026-09-19

### Fixed

- **The assistant cites its sources again.** The chat stripped every `[#n]`
  marker out of the answer -- it was gated behind an ability that the single
  chat no longer has -- so the assistant cited passages nobody could see. The
  markers are rendered as buttons, and the passages the knowledge tool
  returned are listed under the answer: title, position, score and a link
  where the source provides one. Clicking a marker opens the list on that
  passage.
- Markers now continue across the tool calls of one turn: two searches both
  numbering from one made "[#1]" mean two different passages.
- Reopening a conversation brings its citations back, read out of the tool
  output laravel/ai stored.
- The chat's endpoints answer to either door. The panel's assistant is gated
  by `filament-ai.agent.authorize` and the standalone page by the `view`
  ability; denying the page used to take the panel's stylesheet and script
  with it, leaving it unstyled and dead.
- The composer and the empty state still spoke for the retired knowledge
  mode ("ask about the documents").

## [4.0.1] - 2026-09-18

### Fixed

- The assistant settings page drew three controls it never read and never
  wrote -- the chat page, the topbar button and how much history a
  conversation replays. The toggles showed off whatever was stored, and
  switching one on saved nothing. A test now asserts that every assistant key
  in the whitelist is written when the form is saved.
- The Italian and English help texts still pointed at `config/rag.php`.

## [4.0.0] - 2026-09-18

### Changed

- **Breaking: the package speaks its own name.** `config/rag.php` is now
  `config/filament-ai.php` and its keys `filament-ai.*`; environment variables
  are `FILAMENT_AI_*`; console commands are `ai:*`; route names are
  `filament-ai.chat.*` and the chat lives at `/ai/chat`; panel pages sit under
  the `ai/` slug prefix; views and translations resolve as `filament-ai::`
  (the translation file is `messages.php`); gate abilities are unchanged in
  shape but read from the new config root; the facade is `FilamentAi`; and the
  classes that carried the old name -- `RagPlugin`, `RagDashboard`,
  `RagSettings`, `RagPlayground`, `RagManager`, `RagException`,
  `AuthorizeRagChat`, `HasRagNavigation`, `UsesRagConnection` -- are
  `FilamentAiPlugin`, `KnowledgeDashboard`, `KnowledgeSettings`,
  `KnowledgePlayground`, `FilamentAiManager`, `FilamentAiException`,
  `AuthorizeChat`, `HasAiNavigation`, `UsesAiConnection`.
- **Table names are untouched.** They keep the `rag_` prefix, which stays
  configurable under `filament-ai.database.prefix`: no application has to
  migrate its data to take this release. The queue name keeps its `rag`
  default for the same reason -- renaming it would strand whatever is already
  queued.

## [3.0.0] - 2026-09-18

### Added

- **One chat, two modes.** The standalone page and the panel's Assistant page
  now render the same component -- plain HTML and CSS custom properties, no
  Filament UI inside it -- with a **Knowledge** mode (the grounded pipeline,
  citations, feedback) and an **Assistant** mode (the panel agent, its tools
  and approvals). Embedded in a panel it takes its colours from Filament's
  tokens and follows the panel's dark mode.
- **Assistant answers stream**, with a `tool` event for every call and an
  `approval` event for every pending change. Decisions are posted to
  `POST rag/chat/a/{conversation}/decisions` and checked against what is
  actually waiting.
- **A "Keep trying" toggle** in the composer when iterative solving is on and
  the user holds the new `solve` ability: the question starts a run and the
  page follows it wave by wave.
- **`SolveStrategy`.** An application describes how its problems are solved --
  one `SolvePhase` per wave, each with its own instructions, attempts,
  temperature, tools and step limit -- and may sharpen the criteria or bring
  its own verifier. `DefaultStrategy` keeps the previous behaviour. The
  strategy is stored on the run (`solve_runs.strategy`) and each attempt keeps
  its phase (`solve_attempts.phase`); run the new migration.
- `PanelAssistant::onlyTools()` and `withMaxSteps()`.
- The `agent` and `solve` chat abilities.

### Changed

- **Breaking:** `AssistantChat` is no longer a Livewire chat. Its `send()`,
  `decide()`, `newChat()` and `openChat()` actions and the `prompt`,
  `decisions` and `error` properties are gone; the page renders the shared
  component, and turns go over HTTP. A host that extended the page or its
  view must follow.
- `SolveAttemptJob` no longer builds the prompt; the strategy does.
  `SolveOptions` takes a new, last, `strategy` argument.
- The chat routes stay registered while either chat is on. `filament-ai.chat.enabled`
  now switches off the standalone page only, checked when the request arrives.
- `ConversationTranscript::recent()` returns `updated_at` as an ISO 8601
  timestamp instead of a relative phrase.

### Fixed

- Approving a change after the assistant had already looked something up in
  the same turn failed with a provider error, although the change had been
  made. laravel/ai 0.11.2 replays such a pause with a tool result Anthropic
  rejects; the package now binds `ReplaySafeConversationStore`, which replays
  the earlier step on its own. A host that bound its own store keeps it.
- Links in answers are rendered (only `http(s)` and same-site paths).

### Removed

- `resources/views/partials/assistant-styles.blade.php`.

## [2.0.0] - 2026-09-16

The knowledge base is still here; around it there is now an agent that works
inside a Filament panel.

### Added

- **Resource tools.** A Filament resource opts in with `AgentResource` and
  `InteractsWithAgent`, and its tools are derived from what it already
  declares: list and search (over the table's searchable columns and its
  filters, applied by Filament itself), read one record, create, edit, and
  delete when the resource asks for it.
- **Approval before every write.** The turn pauses with a readable summary of
  the change and runs only once the user approves; a deletion is always
  confirmed. Policies are checked on every call, `getEloquentQuery()` keeps
  tenant scoping, and attributes the model hides are never returned.
- **A chat page inside the panel**, with the user's conversation history,
  approval cards, and a topbar button that carries the record on screen into
  the conversation.
- **An admin page for the agent's policies**: what each resource allows, which
  writes may skip approval, the model, the knowledge sources, the record caps.
  It can only narrow what the code declares.
- **An optional code sandbox** (self-hosted Piston) so the agent can write and
  run a program rather than the host writing a tool for every calculation. Off
  by default.
- `KnowledgeSearch`, shared by the MCP tools and the agent's knowledge tools.

### Changed

- **Renamed** from `murkrow/laravel-rag`; the namespace is `Murkrow\FilamentAi`
  and the service provider `FilamentAiServiceProvider`. Table names, the `rag.`
  config keys and the `Rag` facade are unchanged.
- **Filament v5 and `laravel/ai` are required**, alongside PHP 8.3+. The panel
  is no longer an optional surface.
- `laravel-ai` is the default driver for generation and embeddings.

### Deprecated

- The `prism` drivers for generation and embeddings. They still work and will
  be removed in the next minor.

### Fixed

- The knowledge settings page no longer writes back the agent's settings: it
  rendered no field for them but carried them in its state.

[Unreleased]: https://github.com/Murkrow02/filament-ai/compare/v5.1.1...HEAD
[5.1.1]: https://github.com/Murkrow02/filament-ai/compare/v5.1.0...v5.1.1
[5.1.0]: https://github.com/Murkrow02/filament-ai/compare/v5.0.2...v5.1.0
[5.0.2]: https://github.com/Murkrow02/filament-ai/compare/v5.0.1...v5.0.2
[5.0.1]: https://github.com/Murkrow02/filament-ai/compare/v5.0.0...v5.0.1
[5.0.0]: https://github.com/Murkrow02/filament-ai/compare/v4.1.0...v5.0.0
[4.1.0]: https://github.com/Murkrow02/filament-ai/releases/tag/v4.1.0
[4.0.2]: https://github.com/Murkrow02/filament-ai/releases/tag/v4.0.2
[4.0.1]: https://github.com/Murkrow02/filament-ai/releases/tag/v4.0.1
[4.0.0]: https://github.com/Murkrow02/filament-ai/releases/tag/v4.0.0
[3.0.0]: https://github.com/Murkrow02/filament-ai/releases/tag/v3.0.0
[2.0.0]: https://github.com/Murkrow02/filament-ai/releases/tag/v2.0.0
