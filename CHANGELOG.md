# Changelog

Notable changes to `murkrow/filament-ai`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[semantic versioning](https://semver.org/spec/v2.0.0.html).

Versions before 2.0.0 were released under the package's former name,
`murkrow/laravel-rag`, which is abandoned in favour of this one.

## [Unreleased]

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

[Unreleased]: https://github.com/Murkrow02/filament-ai/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/Murkrow02/filament-ai/releases/tag/v2.0.0
