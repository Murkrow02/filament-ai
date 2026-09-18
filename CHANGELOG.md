# Changelog

Notable changes to `murkrow/filament-ai`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[semantic versioning](https://semver.org/spec/v2.0.0.html).

Versions before 2.0.0 were released under the package's former name,
`murkrow/laravel-rag`, which is abandoned in favour of this one.

## [Unreleased]

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

[Unreleased]: https://github.com/Murkrow02/filament-ai/compare/v4.0.1...HEAD
[4.0.1]: https://github.com/Murkrow02/filament-ai/releases/tag/v4.0.1
[4.0.0]: https://github.com/Murkrow02/filament-ai/releases/tag/v4.0.0
[3.0.0]: https://github.com/Murkrow02/filament-ai/releases/tag/v3.0.0
[2.0.0]: https://github.com/Murkrow02/filament-ai/releases/tag/v2.0.0
