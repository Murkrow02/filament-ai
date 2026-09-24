# Filament AI

[![Tests](https://github.com/Murkrow02/filament-ai/actions/workflows/tests.yml/badge.svg)](https://github.com/Murkrow02/filament-ai/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/murkrow/filament-ai.svg)](https://packagist.org/packages/murkrow/filament-ai)
[![License](https://img.shields.io/packagist/l/murkrow/filament-ai.svg)](LICENSE.md)

An assistant for Filament panels, on a knowledge base you describe in configuration: chunking, embeddings, pgvector retrieval, grounded answering, agent tools over your own resources, an MCP server and a control panel.

The package knows nothing about your models. You describe them once in `config/filament-ai.php` — a model, a relation that yields ordered text, a couple of columns — and everything else follows: ingestion, incremental re-indexing, semantic search with page-accurate citations, a chat endpoint, an MCP server for external agents, and a dashboard to drive it all.

```php
FilamentAi::ask('Who convened the council, and when?')->answer;
// "The podestà Guido Novello convened the general council in March. [#1]"
```

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 12 or 13 |
| Database | **PostgreSQL with the `vector` extension** (pgvector 0.5+) |
| Embeddings & generation | [laravel/ai](https://laravel.com/docs/ai-sdk) **^1.0** and any provider it supports — OpenAI, Anthropic, Gemini, Ollama, VoyageAI, Mistral… (Bedrock needs `aws/aws-sdk-php`) |
| Panel | `filament/filament` ^5 |
| Optional | `laravel/mcp` ^1 for the MCP server, `laravel/scout` for hybrid retrieval |

The easiest way to get pgvector is the official image: `pgvector/pgvector:pg17`. A stock `postgres:17` does **not** ship the extension.

If you already have data in an Alpine-based Postgres, do **not** simply swap in that image: it is Debian/glibc, and mounting a musl-built `PGDATA` under a different libc changes collation and can corrupt indexes on text columns. Either dump and restore, or build the extension onto the base you already run:

```dockerfile
FROM postgres:17-alpine
RUN apk add --no-cache --virtual .build build-base git postgresql17-dev \
    && git clone --branch v0.8.1 --depth 1 https://github.com/pgvector/pgvector.git /tmp/pgvector \
    && cd /tmp/pgvector \
    && make USE_PGXS=1 with_llvm=no && make USE_PGXS=1 with_llvm=no install \
    && cd / && rm -rf /tmp/pgvector && apk del .build
```

---

## Installation

```bash
composer require murkrow/filament-ai
php artisan ai:install     # verifies the extension, publishes the config
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"   # laravel/ai's config and conversation tables
php artisan migrate
```

The assistant keeps its conversations -- and every change waiting for approval -- in laravel/ai's tables. Coming from laravel/ai 0.x, run the backfill migration from [laravel/ai's upgrade guide](https://github.com/laravel/ai/blob/1.x/UPGRADE.md) before deploying: until then `ai:status` warns and the chat says it is unavailable instead of failing.

`ai:install` tells you, in plain language, what is missing before anything else can go wrong — a database that cannot host vectors, a missing `job_batches` table, a corpus with no source configured.

Add your provider key and pick your models. Credentials and base URLs live in laravel/ai's `config/ai.php` (`php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"`); `FILAMENT_AI_EMBEDDING_PROVIDER` and `FILAMENT_AI_LLM_PROVIDER` name one of its `providers` and fall back to its defaults when unset:

```dotenv
OPENAI_API_KEY=sk-...

FILAMENT_AI_EMBEDDING_PROVIDER=openai
FILAMENT_AI_EMBEDDING_MODEL=text-embedding-3-small
FILAMENT_AI_EMBEDDING_DIMENSIONS=1536
FILAMENT_AI_LLM_PROVIDER=openai
FILAMENT_AI_LLM_MODEL=gpt-4o-mini

FILAMENT_AI_QUEUE_CONNECTION=redis
FILAMENT_AI_QUEUE=rag   # the queue name keeps its old default; rename it only with an empty queue
```

Then run a worker for the ingestion queue:

```bash
php artisan queue:work redis --queue=rag,default
```

Your `config/filament-ai.php` only needs the keys you actually change: the package's defaults are merged underneath it recursively, so overriding one nested value never drops its siblings. Publish the full, commented file when you want to read the defaults:

```bash
php artisan vendor:publish --tag=filament-ai-config     # every default, documented
php artisan vendor:publish --tag=filament-ai-stubs      # the source stub ai:make:source writes
```

---

## Describing your data

A **source** maps one Eloquent model to a document, and an ordered relation to that document's text segments. It is a class, and it is the only place your own models appear.

```bash
php artisan ai:make:source BookSource --model=App\\Models\\Book --relation=pages --text=content --position=number
```

```php
// app/Knowledge/BookSource.php
namespace App\Knowledge;

use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Murkrow\FilamentAi\Sources\{EloquentSource, Filter, PositionLabels, SegmentMap};

final class BookSource extends EloquentSource
{
    public function key(): string          { return 'books'; }   // stored on every document
    public function label(): string        { return 'Library'; }
    public function icon(): ?string        { return 'heroicon-o-book-open'; }

    protected function model(): string     { return Book::class; }
    protected function keyColumn(): string { return 'id'; }      // becomes external_id
    protected function titleColumn(): ?string { return 'title'; }
    protected function metadata(): array   { return ['author', 'isbn']; }

    protected function segmentMap(): SegmentMap
    {
        return SegmentMap::relation('pages', text: 'content', position: 'number', batchSize: 200);
    }

    /** How a citation reads. */
    protected function positionLabels(): PositionLabels
    {
        return new PositionLabels('Pages :start-:end', 'Page :start');
    }

    /** Only index what is worth indexing. */
    protected function scope(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    /** Drives both `--filter=` on the CLI and the ingestion form. */
    protected function filters(): iterable
    {
        return [
            Filter::ids('ids', 'id', label: 'Specific IDs'),
            Filter::range('id_range', 'id', label: 'ID range'),
            Filter::like('title', label: 'Title contains'),
            Filter::boolean('bad_ocr', label: 'Include badly scanned books', default: false),
        ];
    }

    /** Deep link back into your app. */
    public function url(Document $document, ?Chunk $chunk = null): ?string
    {
        return route('books.show', ['book' => $document->external_id, 'page' => $chunk?->position_start]);
    }
}
```

Then list it — the only knowledge configuration there is:

```php
// config/filament-ai.php
'sources' => [
    App\Knowledge\BookSource::class,
],
```

Sources are resolved through the container, so a source may take constructor dependencies, and it is a plain object you can instantiate in a test.

`position` is the number a human would cite — a page, a section, a timestamp in seconds. It ends up on every chunk and in every citation, so pick something meaningful. A model that carries its whole text in one column says `SegmentMap::column('body')` instead.

### Filters

Every filter is one object, applied by the ingestion query, parsed from `--filter=name:value`, and rendered as the matching field in the Filament form:

| Factory | Accepts | Becomes |
|---|---|---|
| `Filter::ids('ids', 'id')` | `"1,2,3"`, `[1, 2, 3]` | `whereIn` |
| `Filter::range('id_range', 'id')` | `"10-50"`, `"10..50"`, `['from' =>, 'to' =>]` | inclusive bounds |
| `Filter::dateRange('published', 'published_at')` | the same shapes | `whereDate` bounds |
| `Filter::like('title')` | `"garibaldi"` | `like %value%` |
| `Filter::in('lang', ['it' => 'Italian'])` | a list | `whereIn` + multi-select |
| `Filter::boolean('bad_ocr', default: false)` | truthy / falsy | `where(col, bool)` |
| `Filter::isNull('orphans', 'author')` | truthy / falsy | `whereNull` / `whereNotNull` |
| `Filter::callback('recent', fn ($q, $v) => $q->recent($v))` | anything | your closure |

The first argument is the filter's name — what `--filter=` addresses — and the column defaults to it, which is why two filters can narrow the same column. A blank value means "not filtered"; `false` is not blank, so `default: false` constrains every run until someone toggles it. Write your own by implementing `SourceFilter`.

Per-source chunking is typed too, and only what you set is overridden:

```php
protected function chunkingOverrides(): ChunkingOverrides
{
    return new ChunkingOverrides(targetTokens: 320, overlapTokens: 40);
}
```

### Rows too small to be documents

A table of thousands of short rows -- a gazetteer, a glossary, a term list -- is the wrong shape for one-row-one-document: each vector would carry a handful of tokens and they would all look alike. `GroupedEloquentSource` groups the rows instead: a grouping expression's distinct values become the documents, and the rows inside a group become its ordered segments, so each chunk holds dozens of related entries.

```php
final class ToponymSource extends GroupedEloquentSource
{
    public function key(): string           { return 'toponyms'; }

    protected function model(): string      { return Toponym::class; }
    protected function groupBy(): string    { return 'upper(substr(name, 1, 1))'; }  // one document per initial
    protected function textColumn(): string { return 'name'; }

    protected function documentTitle(string $group): string
    {
        return "Toponyms - {$group}";
    }

    public function chunkingOverrides(): ChunkingOverrides
    {
        // Entries are independent: no fact spans a boundary, so overlap is
        // pure cost -- and bridging would stitch the whole letter into one
        // sentence, since names carry no closing punctuation.
        return new ChunkingOverrides(targetTokens: 256, overlapTokens: 0, bridgeSegments: false);
    }
}
```

Positions are ordinals inside the group, so a citation reads "Toponyms - S, entries 120-210". The grouping expression is interpolated into the query: it belongs to the source class and must never come from a request. Filters here select which *documents* a run covers -- a group that matches is ingested whole.

**Not an Eloquent model?** Build a source at runtime:

```php
FilamentAi::source('handbook')
    ->setLabel('Employee handbook')
    ->loadDocumentsUsing(fn (array $filters) => LazyCollection::make(/* … DocumentDraft … */))
    ->loadSegmentsUsing(function (string $id): Generator { yield new Segment(1, $text); })
    ->register();
```

---

## Indexing

```bash
php artisan ai:ingest books                      # queued, incremental
php artisan ai:ingest books --sync                # in this process
php artisan ai:ingest books --dry-run             # estimate only
php artisan ai:ingest books --filter=id_range:1-50
php artisan ai:ingest books --mode=full           # re-chunk everything
php artisan ai:ingest books --mode=embeddings_only
```

`--dry-run` answers the question worth asking first:

```
  source ............................. Library (books)
  documents .......................... 1,240
  estimated chunks ................... ~48,000
  estimated tokens ................... ~24,600,000
  estimated cost ..................... ~$0.4920
```

### Incremental re-indexing is the default, and it is cheap

Chunks are matched by a hash of their embedding input. Re-running an ingestion over a corpus where one page changed re-embeds the chunks covering that page and **keeps every other vector**. A nightly `ai:ingest books` over an unchanged library costs nothing and finishes in seconds.

Three things invalidate a chunk: its text, the document title (it is part of the embedded context header), and the chunking parameters. All three are captured in the hash, so the system can never quietly serve a stale mixture.

### Deletions

Ingestion only ever walks what a source still returns, so it cannot notice that a record is gone: a deleted book keeps answering questions until something removes its document. Two mechanisms, and you want both.

Immediately, from wherever the host deletes the record — a model observer is the place that cannot be bypassed:

```php
use Murkrow\FilamentAi\Facades\FilamentAi;

public function deleted(Book $book): void
{
    FilamentAi::forget('books', $book->getKey());
}
```

Chunks and citations cascade from the document row, and the vector lives on the chunk row, so that one call takes the embeddings with it. It returns `false` when nothing was indexed under that id.

Nightly, as the safety net for the delete paths that skip model events (`Model::query()->delete()`, a truncate, a manual `DELETE`):

```php
Schedule::job(new PruneOrphanChunksJob)->dailyAt('03:00');
```

It asks each source whether every document's host record still exists, and drops the ones that do not.

**A grouped source is different.** Its `external_id` is the group, not the row — deleting one of the thousands of rows that share a document must *re-ingest that group*, not forget it:

```php
FilamentAi::ingest('toponyms', ['initials' => 'S']);   // incremental: re-embeds only what changed
```

`forget()` on a grouped source would delete every entry that shares the group. Reach for it only when the whole group is gone, and let the nightly prune handle that case instead.

### Chunking

Text is split into sentence-aligned windows with overlap, which sounds ordinary and is not, because the input is usually worse than prose:

- **Windows overlap.** A fact stated across a chunk boundary still appears intact in at least one chunk.
- **Sentences are stitched across segment boundaries.** A sentence cut in half by a page break is rejoined, and the resulting chunk honestly reports `Pages 12-13`.
- **Page ranges are exact, not estimated.** Every sentence carries its page, so `position_start` and `position_end` fall out of the window rather than being guessed.
- **OCR without punctuation is handled.** A scanned page with no full stops would otherwise arrive as one enormous "sentence"; it is split on whitespace instead.
- **Ligatures, hyphenation and control characters are normalised.** `ﬁ` becomes `fi`, `paro-\nla` becomes `parola`, and the replacement character disappears — all of which matter because they otherwise tokenise as garbage.
- **Stub chunks are merged backwards.** A 20-token trailing fragment scores high on similarity while saying nothing.

Every parameter is configurable per source, and the chunker is deterministic: the same input always produces the same hashes, which is what makes incremental indexing trustworthy.

---

## Searching and answering

```php
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Data\{AnswerOptions, RetrievalOptions};

// Retrieval only — no model call, no cost.
$chunks = FilamentAi::search('who convened the council?');

// Grounded answer with citations.
$result = FilamentAi::ask('who convened the council?', new AnswerOptions(
    retrieval: new RetrievalOptions(
        sourceKeys:   ['books'],
        externalIds:  ['42'],       // one book
        positionFrom: 10,           // pages 10–20
        positionTo:   20,
        topK:         6,
    ),
));

$result->answer;                    // the text
$result->refused;                   // true when the corpus could not support it
$result->usedCitations();           // only the ones the model actually cited
$result->usage->costUsd();
```

Streaming:

```php
$stream = FilamentAi::stream($question);

foreach ($stream as $delta) {
    echo $delta;
}

$result = $stream->getReturn();
```

### The retrieval pipeline

Over-fetch → optional lexical fusion → score floor → de-duplication → MMR → optional neighbour expansion → top-k.

Two stages earn particular mention. **De-duplication is mandatory, not cosmetic**: adjacent chunks deliberately share their overlap, so a passage on a boundary reliably matches twice, and without collapsing them half your context window is the same paragraph. **MMR** trades a little relevance for coverage, because eight paraphrases of one passage are worth barely more than one.

Filters compile to SQL and run inside the ranking query, so a question scoped to one book touches only that book's vectors. For anything the declarative filters cannot express, `RetrievalOptions::$constrain` takes a closure over the Eloquent builder.

### Grounding

The system prompt is a publishable Blade view. The default is deliberately strict: answer only from the numbered context blocks, cite every claim as `[#n]`, refuse rather than speculate, never invent a page number, and quote OCR text as it is rather than silently correcting it.

Two guardrails are enforced in code rather than trusted to the model: **when retrieval returns nothing the model is never called at all** (an LLM handed no context will answer from its parameters, which is the exact failure a grounded system exists to prevent), and an answer citing nothing is treated as ungrounded and reported as a refusal.

```bash
php artisan ai:search "chi era il podestà" --source=books --from=40 --to=60
php artisan ai:ask "chi era il podestà" --stream
php artisan ai:status
```

### Hybrid retrieval (optional)

Embeddings are weakest at exactly what lexical search is best at: names, dates, catalogue numbers, rare proper nouns. Set `FILAMENT_AI_HYBRID_DRIVER=tsvector` to fuse a PostgreSQL full-text leg into the ranking with reciprocal rank fusion, or `scout` to use whichever engine Scout is already configured with.

---

## Panel agent

An assistant the panel user can ask instead of navigating. It reads the knowledge base and every Filament resource that opts in, always as the signed-in user.

Opt a resource in with one interface and one trait. Nothing else is written for the agent: its tools are derived from what the resource already declares.

```php
use Murkrow\FilamentAi\Agent\Resources\AgentResource;
use Murkrow\FilamentAi\Agent\Resources\InteractsWithAgent;

class OrderResource extends Resource implements AgentResource
{
    use InteractsWithAgent;
}
```

That resource now gives the agent `orders_list` (free-text search over the table's searchable columns, paginated, newest first) and `orders_view` (one record, with the table's columns and the form's fields). The table's own filters become arguments too -- `status`, `customer`, a ternary toggle -- and are applied by Filament itself, so they narrow exactly as they do in the panel. Narrow or describe it when the defaults are not right:

```php
public static function agentTools(AgentTools $tools): AgentTools
{
    return $tools
        ->only(AgentTools::LIST)
        ->searchUsing(['number', 'customer_name'])
        ->limit(10)
        ->describe('Customer orders. "Open" means placed but not shipped.');
}
```

What the agent can and cannot reach:

- It acts in a panel and, on a tenant panel, in a tenant -- the page it was opened from sends both, and the server checks them the way Filament's own `SetUpPanel` / `IdentifyTenant` middleware do. Records come from `Resource::getEloquentQuery()` with the panel's tenant scope active, and created records are assigned to the current tenant by Filament's own observer.
- A user who could not open the panel (`FilamentUser::canAccessPanel()`), or a tenant panel with no tenant the user may access, gets **no** resource tools at all -- not unscoped ones.
- The resource's policies (`viewAny`, `view`, `create`, `update`, `delete`) are checked on every call. A resource the user may not view is not even offered to the model.
- Attributes a model hides -- `$hidden`, or anything missing from `$visible` -- are never returned, on related models too (`customer.api_token`).
- Everything a tool returns is treated as data, not instructions: the agent's rules say so, record titles are quoted into its instructions, and web pages come back marked as untrusted.
- Resources without `AgentResource` are invisible to it.

### Changing data

An opted-in resource also gets `orders_create` and `orders_edit`, and `orders_delete` when it asks for it with `->with(AgentTools::DELETE)`.

Writes go **through the resource's own form**, run the way the panel's create and edit pages run it (`Schema::getState()`): the form is built for the operation (`create` / `edit`, with the record), so `visible()`/`hidden()`, `visibleOn()`, `disabledOn()`, `dehydrated(false)`, `dehydrateStateUsing()`, every validation rule -- including a Select's options and a relationship Select's own scoped query -- and fields inside a `->relationship()` layout behave exactly as they do for the user on the page. The agent can never do more than the user could. Arguments the form did not take are reported back to the model as not saved.

Three things are deliberately different from the page:

- **Password fields are never offered.** A secret typed to a language model is in the provider's logs and in the stored conversation.
- **Page hooks do not run** -- the agent never mounts a page. What a page's `mutateFormDataBeforeCreate()` / `mutateFormDataBeforeSave()` does that must also happen for the agent goes in two optional static methods on the resource:

  ```php
  public static function agentMutateBeforeCreate(array $data): array
  {
      return [...$data, 'user_id' => auth()->id()];
  }

  public static function agentMutateBeforeSave(Model $record, array $data): array { /* ... */ }
  ```

- **Nested resources** (with a parent resource) get no write tools.

No write runs on the model's word alone. The turn pauses and the chat shows a card with the change in the form's labels -- the record's current value next to the new one, option labels instead of keys, dates in the reader's format -- and the tool runs only once the user approves. A change the form would refuse is never put to the user: the model gets the validation errors instead. The decision is taken under a per-conversation lock, so a double click or a second tab cannot run the same write twice.

```php
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

$response = (new PanelAssistant)->forUser(auth()->user())->prompt('Mark order 1042 as shipped');

foreach ($response->pendingApprovals as $approval) {
    // show $approval->reason to the user, then resume with their decision:
    (new PanelAssistant)
        ->continue($response->conversationId, as: auth()->user())
        ->prompt(Decisions::from([$approval->id => Decision::approve()]));
}
```

Creation and edits can skip the confirmation per resource with `->withoutApproval(AgentTools::CREATE, AgentTools::EDIT)`. Think twice: record content the agent reads can then steer a write nobody looks at. A deletion is always confirmed, whatever the code or the settings say.

To turn a write back to asking (for instance per environment), `->requireApproval(AgentTools::EDIT)` undoes `withoutApproval()`.

### Documents only some users may read

By default every indexed document of an allowed source is readable by anyone who can use the assistant (or the MCP tools). A source whose documents belong to someone implements `ScopesDocumentsToUser`, and searches and document reads are narrowed for the signed-in user:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Murkrow\FilamentAi\Contracts\ScopesDocumentsToUser;

final class InvoiceSource extends EloquentSource implements ScopesDocumentsToUser
{
    protected function metadata(): array
    {
        return ['customer_id'];   // copied onto each indexed document
    }

    public function scopeDocumentsFor(Builder $documents, ?Authenticatable $user, string $table): void
    {
        if ($user?->isAdmin()) {
            return;
        }

        // Compare JSON values as strings: PostgreSQL will not compare text with an integer.
        $documents->where("{$table}.metadata->customer_id", (string) $user?->customer_id);
    }
}
```

Qualify every column with `$table`. A scope that throws leaves that source out of the search rather than searching it unscoped.

### Deciding what it may do, from the panel

`Assistant settings` (next to the chat, gated by `filament-ai.filament.authorize`) turns the configuration above into a form: provider and model, whether it may search documents and which sources, how many records an answer may carry, and then one section per opted-in resource -- which abilities it keeps, which writes may run without asking, how many records that resource returns.

It can only take away. A resource that never implemented `AgentResource` is not listed, an ability its class does not offer cannot be ticked, and a write the code asks the user about cannot be made silent from here -- only a write the code already runs without asking can be sent back to asking. Anything left exactly as the code declared it is not stored at all, so a later change to `agentTools()` is picked up instead of being shadowed by a saved row. Settings live in the same table as the knowledge settings and are layered over `config/filament-ai.php` on boot.

### Running code

Some questions are not lookups: anagrams, permutations, ciphers, parsing, arithmetic over many rows. Writing a tool for each is a losing battle, so the agent can be given a sandbox and write the program itself.

It is off by default. Switch it on with a sandbox to point at -- the shipped driver talks to a self-hosted [Piston](https://github.com/engineer-man/piston):

```yaml
# docker-compose.yml -- a container of its own, port not published
piston:
    image: ghcr.io/engineer-man/piston:latest
    privileged: true          # isolate(1) needs cgroup and mount privileges
    environment:
        - PISTON_RUN_TIMEOUT=10000
    tmpfs:
        - /piston/jobs:exec,uid=1000,gid=1000,mode=711
        - /tmp:exec
```

```dotenv
FILAMENT_AI_AGENT_SANDBOX=true
FILAMENT_AI_AGENT_SANDBOX_URL=http://piston:2000
FILAMENT_AI_AGENT_SANDBOX_PYTHON=3.12.0   # pin it, or answers change when the sandbox does
FILAMENT_AI_AGENT_MAX_STEPS=10            # write, run, read the error, fix
```

Install the language once: `POST /api/v2/packages {"language":"python","version":"3.12.0"}`.

What the agent gets is `run_code`: a language, a program, optional stdin, and back come stdout and stderr, truncated from the end -- the last lines of a traceback are the ones worth keeping. The sandbox reaches nothing: not this application, not the database, not the internet. Data a program needs is data the agent read with another tool and passed in. Every run is logged with its snippet.

The privilege is real and belongs to the sandbox container, not the app: never point the driver at something that shares this application's filesystem, database or network.

Once a sandbox is configured, the rest is on the `Assistant settings` page: whether the agent may run code at all, which of the installed languages it may use (the page asks the sandbox), the time limit, and how much of a program and of its output to keep. The url and the driver stay in `config/filament-ai.php` -- a form that decides where the application posts code is a way in, not a setting.

### Searching the web

The knowledge base is closed; the web isn't. For anything current or simply outside the indexed corpus, the agent can be given two tools: `search_web`, which returns titles, urls and snippets, and `fetch_web_page`, which reads one page in full. Deliberately two calls, not one -- the agent reads snippets first and fetches the whole page only for the one or two results that actually deserve it, instead of paying for ten full pages on every query.

It is off by default. The shipped driver is Google's [Programmable Search Engine](https://programmablesearchengine.google.com) (Custom Search JSON API): create an engine set to search the whole web, and an API key with the "Custom Search API" enabled at the [Google Cloud console](https://console.cloud.google.com/apis/credentials).

```dotenv
FILAMENT_AI_AGENT_WEB_SEARCH=true
FILAMENT_AI_GOOGLE_SEARCH_API_KEY=...
FILAMENT_AI_GOOGLE_SEARCH_CX=...          # the search engine id, not the key
```

`search_web` caches identical `(query, limit)` pairs for `web_search.cache_ttl` seconds (an hour by default), so the same question asked twice in a conversation is not billed twice. Google's own cap of 10 results per request applies regardless of what is configured.

`fetch_web_page` is **off unless switched on** (`FILAMENT_AI_AGENT_WEB_FETCH=true`): it makes this server fetch a url the model chose. It only accepts plain `http`/`https` urls resolving to public addresses -- no loopback, private, carrier-grade NAT, link-local (cloud metadata) or IPv6 forms that embed them -- and connects to exactly the address it checked, so DNS cannot answer differently the second time. Every redirect is checked and pinned again, the body is read as a stream and abandoned at `max_bytes`, and the text comes back marked as untrusted content. Every search and fetch is logged with the user who asked.

The Google key is sent as the `X-Goog-Api-Key` header, never in the url. The provider's key, engine id and endpoint are not editable from the panel; on/off, result counts and caching are.

A driver of your own -- Bing, Brave, an internal index -- is a class implementing `WebSearchEngine`, registered on `WebSearchManager`:

```php
use Murkrow\FilamentAi\Agent\WebSearch\WebSearchManager;

$this->app->make(WebSearchManager::class)->register('bing', function ($app) {
    return new BingSearchEngine(/* ... */);
});
```

then `FILAMENT_AI_AGENT_WEB_SEARCH_DRIVER=bing`.

### Keeping at it until it works

Some questions are not answered in one turn: a riddle, a puzzle, anything where the first idea is usually wrong. `Solver` runs the assistant several times over, judges the answers and starts again from what was wrong.

```php
use Murkrow\FilamentAi\Agent\Solving\Solver;
use Murkrow\FilamentAi\Data\SolveOptions;

$run = app(Solver::class)->solve(
    goal: 'Trova la parola chiave nascosta in questo indizio: ...',
    options: new SolveOptions(
        criteria: 'Una sola parola italiana, nome di una città, giustificata dall\'indizio.',
        attemptsPerWave: 4,
        maxWaves: 3,
    ),
);

$run->status;        // solved | exhausted | failed
$run->best?->answer; // the closest attempt, even when nothing was accepted
$run->message;       // what to tell the user when it gave up
```

A wave is N attempts running in parallel on the queue, each a full turn with every tool the assistant has -- resources, knowledge, the sandbox. They are spread apart by temperature and never see each other: that is where the variety comes from. Then a judge grades each answer against your criteria and, if none passes, the next wave starts with the reasons the last one failed.

It always stops. Waves, tokens, cost and seconds are four independent budgets, and the first to run out ends the run as `exhausted` -- keeping the best attempt and the reason it was rejected, which is what the user is told.

Off by default (`filament-ai.agent.solving`), because it multiplies the cost of an answer by attempts × waves plus a judgement each; the panel's `Assistant settings` page carries the switches, and `Solve runs` shows every attempt with its score and the judge's reason. It needs a queue worker: the batch's completion is what starts the next wave.

The judge is a `Verifier`. The shipped one is a language model; an application that already knows what correct means -- a treasure hunt holding the answer, a checksum, a test suite -- binds its own and pays nothing per attempt.

#### Describing how a problem is solved

The engine is generic; the method does not have to be. A `SolveStrategy` decides what each wave tries, how many attempts it gets and with which tools, so an application that knows how its own problems are usually cracked can say so:

```php
use Murkrow\FilamentAi\Agent\Solving\DefaultStrategy;
use Murkrow\FilamentAi\Data\SolvePhase;

final class HuntStrategy extends DefaultStrategy
{
    public function phases(): ?int
    {
        return 3; // the method has three steps, so the run has three waves
    }

    public function phaseFor(int $wave): SolvePhase
    {
        return match ($wave) {
            1 => new SolvePhase(
                label: 'Anagrams',
                instructions: 'Generate the permutations of the odd words with run_code and keep the place names.',
                attempts: 3,
                tools: ['run_code'],
                maxSteps: 8,
            ),
            2 => new SolvePhase(
                label: 'Archive',
                instructions: 'Look the candidates up in the documents and keep the one they mention.',
                tools: ['search_knowledge', 'fetch_document'],
            ),
            default => new SolvePhase(label: 'Synthesis', temperature: 0.2, attempts: 1),
        };
    }
}
```

Name it in `filament-ai.agent.solving.strategy`, or per run with `new SolveOptions(strategy: HuntStrategy::class)`. A phase only ever narrows the tools; one that names none gets all of them. The strategy may also sharpen the criteria (`criteriaFor()`) and bring its own verifier (`verifier()`), and it is stored on the run, so changing the config halfway through does not change the method of a run already going. `DefaultStrategy` is what every run did before this existed: one phase, repeated, every tool.

### In the panel

The plugin adds an **Assistant** page to the panel. It is not a second chat: it renders the same component as the standalone page at `/ai/chat` (see [Chat page](#chat-page)), talking to the same endpoints, with its colours taken from the panel's theme: tools, citations, and a card with Approve and Reject for every pending change. A button next to global search opens it about the page on screen, so "this order" means the order being viewed. Assistant threads live in laravel/ai's conversation tables -- run its migrations -- and are only ever visible to the user who wrote them.

```php
// config/filament-ai.php
'agent' => [
    'assistant' => \App\Ai\Assistant::class,      // your PanelAssistant subclass
    'authorize' => [AssistantPolicy::class, 'use'],   // callable; survives config:cache
    'chat' => ['slug' => 'assistant', 'topbar_button' => true],
],
```

Answers stream, tool calls included. When iterative solving is on, the composer shows a **Keep trying** toggle: the question then starts a run instead of a turn, and the page follows it wave by wave until it ends, with a link to every attempt in `Solve runs` for the `debug` ability. The run lives on the queue, in the panel, tenant and user it was started by, and its attempts get read tools only -- nobody is there to approve a write, so closing the page does not stop it.

The agent itself works with no class of your own:

```php
use Murkrow\FilamentAi\Agent\PanelAssistant;

(new PanelAssistant)
    ->onPage(OrderResource::class, $order)   // so "this order" means something
    ->prompt('Has this customer ordered before?');
```

Extend it to give it a voice and a domain (`persona()`, `domain()`, `additionalTools()`). Knowledge sources it may read and the default record cap live under `filament-ai.agent`.

---

## MCP server

With `laravel/mcp` installed, the package registers a server automatically — no route file to publish.

| | |
|---|---|
| `search_knowledge` | semantic search, filterable by source, document and position range |
| `fetch_document` | read a contiguous span around a hit |
| `answer_question` | full server-side RAG with citations |
| `documents` (resource) | what is indexed, so a client can discover identifiers before searching |
| `grounded_answer` (prompt) | instructions for a client that drives retrieval itself |

Rename the tools to suit your domain — the name is most of what a model uses to decide whether to reach for a tool:

```dotenv
FILAMENT_AI_MCP_TOOL_SEARCH=search_books_knowledge
FILAMENT_AI_MCP_WEB_PATH=mcp/knowledge
```

```bash
php artisan mcp:inspector knowledge
claude mcp add --transport http knowledge https://your-app.test/mcp/knowledge
```

Restrict what MCP can reach with `filament-ai.mcp.sources`. An empty allow-list exposes nothing.

---

## Filament panel

```php
// app/Providers/Filament/AdminPanelProvider.php
->plugin(\Murkrow\FilamentAi\Filament\FilamentAiPlugin::make())
```

That is the whole installation. Add `'Knowledge'` to your panel's `navigationGroups()`, or point `filament-ai.filament.navigation_group` at a group you already have.

### Styling

Nothing to build. The panel's pages are styled with Filament's own components
and inline layout, so they use the stylesheet Filament already publishes -- no
custom theme, no Tailwind config, no npm dependency in your application.

That constraint is why you will find inline `style` attributes and CSS
variables (`var(--gray-500)`, `var(--primary-500)`) rather than utility classes
in this package's views: Filament ships a precompiled stylesheet containing its
semantic `fi-*` classes and nothing else, so a utility like `grid-cols-4` would
not exist unless every host application built a theme for it.

---

## Chat page

A standalone chat UI, served by the package and independent of Filament: its
own route, its own stylesheet, its own layout.

```
/ai/chat
```

Nothing to publish and nothing to build. Set `FILAMENT_AI_CHAT_PATH` to move it,
`FILAMENT_AI_CHAT_ENABLED=false` to switch it off.

The panel's Assistant page renders this same component, so the two are one
chat with two doors. Switching the standalone page off removes the page only:
the endpoints behind it stay up while the panel chat is on
(`filament-ai.agent.chat.enabled`), because that page talks to them too. The
standalone page acts in `filament-ai.chat.panel` (the default panel when null)
(`FILAMENT_AI_CHAT_PANEL`) and, on a tenant panel, in the user's default tenant.

- **Conversations are saved per user** in laravel/ai's tables and listed in the
  sidebar, renameable and deletable. A reload, a second tab and a turn paused
  for approval all show the same thing.
- **The answer streams**, with each step the assistant takes shown in plain
  words ("Search customers", "Edit a task") and citation markers that open the
  passage they point at.
- **Changes wait on a card** that shows the record and each field's current and
  new value, with Approve and Reject.

### Who sees what

A person using the chat sees sentences: what the assistant did, what it wants
to change, and -- when something fails -- that it failed and what to do. The
technical side is for whoever debugs the assistant, behind its own ability.

| Ability | Controls | Default |
|---|---|---|
| `view` | reaching the standalone page at all | on |
| `history` | the sidebar of saved conversations | on |
| `delete` | renaming and deleting one's own | on |
| `model` | the model picker, when models are on offer | on |
| `settings` | the settings panel | on |
| `solve` | the **Keep trying** toggle | on |
| `export` | copying an answer | on |
| `cost` | what each answer cost | **off** |
| `debug` | raw errors, tool names and arguments, model, tokens, cost, retrieval scores, the solve run link, setup hints | **off** |

Each takes one of four shapes in `config/filament-ai.php`:

```php
'chat' => [
    'abilities' => [
        'solve' => false,                                  // a literal
        'cost' => 'see assistant costs',                   // a permission name, checked with $user->can()
        'debug' => [AssistantPolicy::class, 'debug'],      // any callable: fn (?Authenticatable $user): bool
        'model' => null,                                   // the package default
    ],
],
```

`Gate::define('filament-ai.chat.debug', ...)` in your own provider overrides all of it.
**A closure here cannot be `config:cache`d** -- use a `[Policy::class, 'method']`
array, which is callable and survives `var_export()`. The check is not cosmetic:
what an ability denies is never put in the payload or the stream at all.

### Notes

- **Authentication is `filament-ai.chat.middleware`**, `['web', 'auth']` by default.
  An application whose login route is not *named* `login` (a Filament panel's
  is `filament.<panel>.auth.login`) must say so here, or Laravel's `auth`
  middleware cannot build its redirect for a guest.
- **Decisions are serialised with a cache lock**, so the cache store must
  support locks (database, redis, memcached, file, array).
- **Streaming under Octane or behind a buffering proxy** needs the response not
  to be buffered (`X-Accel-Buffering: no` is sent; check your server's own
  buffering).
- The stylesheet and script are served from inside the package by a route, not
  published, so they can never be a stale copy in `public/`. Publish them with
  `--tag=filament-ai-chat-assets` if you would rather serve them yourself.

---

## Operating it

```bash
php artisan ai:status              # coverage, stale vectors, recent runs, spend
php artisan ai:status --watch
php artisan ai:sources
php artisan ai:make:source BookSource --model=App\\Models\\Book
php artisan ai:vector:reindex      # rebuild the ANN index after a bulk load
php artisan ai:purge books --embeddings-only
```

**Build the index after a bulk load, not before.** `ai:vector:reindex` drops and rebuilds it, which produces a better graph and is substantially faster than incremental inserts. Raise `maintenance_work_mem` first on a large corpus.

**Changing the embedding model invalidates every vector.** Vectors from two models are not comparable, and a pgvector column has a fixed width that the migration set once, from the config of that moment. The change is a deployment, not a setting: update the config, then run `ai:vector:reindex`. When `filament-ai.embeddings.dimensions` no longer matches the column, the command says so, discards the stored vectors, resizes the column and rebuilds the index; re-embed afterwards with `ai:ingest <source> --mode=embeddings_only`. A new model with the same width needs only that last step, and `ai:status` reports vectors from another model as stale so the condition is visible rather than silent.

### Cost

Roughly, for a 1,000-book library of ~250 pages each at ~350 tokens per page:

| | |
|---|---|
| Source tokens | ~87M |
| Chunks at 512 tokens, 15% overlap | ~200,000 |
| Full index with `text-embedding-3-small` | **~$2** |
| Incremental re-run, nothing changed | $0 |

The dominant cost is wall-clock time against the provider's API, not money. Batches of 96 chunks per request and parallel workers are what move that number; the built-in rate limiter keeps a bulk run from burning its retry budget against a 429.

Two settings shape a queued run: `filament-ai.queue.chunks_per_job` is how many chunks one job carries, and `filament-ai.embeddings.batch_size` how many of them go into one embedding request, so each job makes ⌈chunks_per_job ÷ batch_size⌉ calls. A self-hosted embedder such as Ollama serves requests strictly one at a time: there, a small `batch_size` (1–2) keeps a search query from waiting behind a long ingestion request, at no cost in throughput, and a second worker adds wait rather than speed.

---

## Extending it

Everything behind a contract can be replaced by binding your own implementation:

| Contract | Default | Why you might swap it |
|---|---|---|
| `VectorStore` | `PgVectorStore` | another vector database |
| `EmbeddingProvider` | laravel/ai | an in-house inference service |
| `LanguageModel` | laravel/ai | a bespoke client |
| `Chunker` | `SlidingWindowChunker` | structure-aware splitting |
| `Retriever` / `Answerer` | defaults | a different pipeline |
| `LexicalSearch` | none | your own keyword engine |
| `TokenEstimator` | heuristic | `TiktokenEstimator` for exact counts |

For tests, `FakeEmbeddingProvider` and `FakeLanguageModel` make the whole pipeline runnable with no API key and no network.

---

## Testing the package

```bash
composer install
vendor/bin/pest                       # unit + feature on SQLite
vendor/bin/pest --testsuite=Pgvector  # needs a real PostgreSQL with pgvector
```

The pgvector suite skips itself when no database is reachable. Point it somewhere with:

```dotenv
FILAMENT_AI_TEST_PG_HOST=localhost
FILAMENT_AI_TEST_PG_PORT=55432
```

```bash
docker run -d --name fai-test-pg -e POSTGRES_USER=rag -e POSTGRES_PASSWORD=rag \
  -e POSTGRES_DB=rag_test -p 55432:5432 pgvector/pgvector:pg17
```

CI runs the whole suite, pgvector included, on PHP 8.3–8.4 for every push and pull request.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Changes are logged in [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE.md](LICENSE.md).
