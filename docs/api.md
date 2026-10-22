# Token API

The panel assistant for clients that are not a browser page — a mobile or desktop app —
authenticated with a token instead of a session. It is the same turn as the chat page
(`ChatTurnResponder`): the same lock per conversation, the same approvals, the same events and
the same abilities (`ChatAbilities`). Only the transport differs.

## Switching it on

```php
// config/filament-ai.php (host overrides only)
'api' => [
    'enabled' => true,                         // FILAMENT_AI_API_ENABLED
    'prefix' => 'api/ai',                      // FILAMENT_AI_API_PREFIX
    'middleware' => ['api', 'auth:sanctum'],   // stateless: no session, no CSRF
    'throttle' => '30,1',                      // ask + transcribe
    'assistant' => App\Ai\Assistant::class,    // null: agent.assistant
    'resources' => false,                      // resource tools need a panel; see below
    'authorize' => [App\Ai\AiAccess::class, 'usesApi'], // callable(user): bool, optional
],
```

The API answers to the agent's own switches as well: `filament-ai.enabled`, `agent.enabled`,
`agent.chat.enabled` and `agent.authorize`. The package does not require Sanctum; any
middleware that authenticates the request works.

**Resources.** With `resources` off (the default) API turns have the knowledge tools and the
host's own tools (`PanelAssistant::additionalTools()`), never the panel's resource tools, and any
user the gates admit may use the API — they need not be able to open the panel. With it on,
`BootAssistantPanel` runs on every API request (send `panel` and `tenant` as the chat does) and
the resource tools are offered only to users who can open that panel. Set
`require_panel_access` to `false` (`FILAMENT_AI_API_REQUIRE_PANEL_ACCESS`) for app users with no panel
login: the resources' own policies (`viewAny`, `view`, ...) then decide alone, as they already do on
every tool call; tenancy checks still apply.

## Conventions

- Every response is JSON or an event stream. The API forces `Accept: application/json` before the
  host's middleware, so an expired token is a `401` with `{message}`, never a redirect.
- Errors: `401` unauthenticated, `403 {message}` not allowed, `404` unknown or foreign conversation
  (the same answer on purpose), `409 {message, pending?}` busy or waiting for a decision,
  `422 {message, errors}` validation, `429` throttled.
- Conversation ids are laravel/ai's (UUIDv7 strings).

## Endpoints

All paths are relative to the prefix (`/api/ai` by default). Route names are `filament-ai.api.*`.

### `GET config`

What the client needs before its first question.

```json
{
  "abilities": {"view": true, "history": true, "delete": true, "folders": false, "model": false,
                "settings": true, "cost": false, "export": true, "voice": true, "debug": false},
  "installed": true,
  "brand": {"name": "Alivia", "logo": null, "accent": "#2f6f4f"},
  "models": {},
  "currentModel": null,
  "suggestions": ["Cosa sta finendo?"],
  "voice": {"maxSeconds": 30, "engine": "local", "language": "it",
            "vocabulary": {"prompt": "...", "phrases": ["..."]}, "autoSend": true, "local": {"model": "...", "dtype": "q4", "library": "..."}},
  "transcribe": false,
  "maxQuestionLength": 4000
}
```

`voice` is null without the `voice` ability or with dictation off. `transcribe` is true only when
the server transcribes (`chat.voice.engine = server`); a client that recognises speech on the
device can use `voice.language`, `voice.vocabulary` and `voice.autoSend` and ignore the rest.

### `GET conversations?limit=20`

The user's own threads, newest first (`limit` 1–100, default `agent.chat.history`). Empty without
the `history` ability.

```json
{"data": [{"uuid": "0199...", "title": "Giacenze", "last_message_at": "2026-10-19T10:00:00+00:00", "folder_id": null}]}
```

### `GET conversations/{uuid}`

```json
{
  "uuid": "0199...",
  "title": "Giacenze",
  "messages": [
    {"role": "user", "content": "Cosa sta finendo?", "tools": [], "passages": []},
    {"role": "assistant", "content": "Markdown...", "tools": [{"id": "call_1", "label": "Elenco giacenze", "status": "done"}], "passages": []}
  ],
  "pending": [ /* approval cards, see below */ ]
}
```

A failed turn has `"failed": true, "failure": "<sentence>"`. Tool `status` is `pending`, `done`,
`failed` or `denied`.

### `PATCH conversations/{uuid}` — `{"title": "..."}` → `{"uuid", "title"}`

### `DELETE conversations/{uuid}` → `{"deleted": true}`

Both need the `delete` ability.

### `POST ask`

```json
{"question": "Cosa sta finendo?", "conversation": "0199... or null", "model": null}
```

Omit `conversation` (or send one that is not the user's) to start a new thread. `model` is
dropped unless the user has the `model` ability. With `resources` on, `resource`, `record`,
`panel` and `tenant` work as in the chat.

Answers with a `text/event-stream` (see Events). With `?stream=0` it answers with the `done`
document as JSON instead (`502` with the `error` document if the turn failed): simpler for a
client that cannot read a stream, at the cost of seeing nothing until the end.

`409 {message, pending}` while a previous turn waits for a decision; `409 {message}` while
another request holds the conversation.

### `POST conversations/{uuid}/decisions`

```json
{"decisions": {"call_1": true, "call_2": false}}
```

Every pending call must be decided, or `409 {message, pending}`. Answers with the resumed turn,
streamed (or `?stream=0`).

### `POST transcribe`

Multipart `audio` → `{text}`. Only while `chat.voice.engine = server`; `403` otherwise.

## Events

`event: <name>\ndata: <json>\n\n`, in this order:

| Event | Data |
|---|---|
| `start` | `{conversation}` — null for a new thread until `done` |
| `delta` | `{text}` — append to the answer |
| `tool` | `{id, label, status: running\|done\|failed\|denied, name?, error?}` — same `id` updates the same step |
| `approval` | `{calls: [card]}` — the turn pauses after this |
| `sources` | `{passages: [...]}` — knowledge passages, when the knowledge tools are on |
| `done` | `{conversation, answer, pending: [card], cost_usd, model, tokens, passages}` |
| `error` | `{message, detail?}` |

`name`, `error`, `detail`, `model` and `tokens` are filled only for the `debug` ability; `cost_usd`
for `cost` or `debug`.

### Approval card

```json
{
  "id": "call_1",
  "title": "Crea articolo",
  "record": null,
  "changes": [{"label": "Nome", "before": null, "after": "Camicia", "missing": false}],
  "summary": null,
  "warnings": [],
  "tool": "items_create", "arguments": {}
}
```

`tool` and `arguments` only for `debug`. `warnings` (optional, may be absent) are lines to show
prominently: an application's own write tool (`AgentAction`) uses them for "last piece in stock" and
the like. Draw the changes, then post the decision.
