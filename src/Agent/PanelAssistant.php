<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Murkrow\FilamentAi\Agent\Tools\FetchDocument;
use Murkrow\FilamentAi\Agent\Tools\RunCode;
use Murkrow\FilamentAi\Agent\Tools\SearchKnowledge;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Throwable;

/**
 * The agent a panel user talks to.
 *
 * Works with no subclass at all: its tools are the knowledge base plus every
 * resource that opted in, and its instructions describe the panel, the user and
 * the page they are on. A host extends it to give it a voice and a domain:
 *
 *     class Assistant extends PanelAssistant
 *     {
 *         protected function persona(): string
 *         {
 *             return 'You are Ask Acme, the back-office assistant.';
 *         }
 *
 *         protected function domain(): ?string
 *         {
 *             return 'Orders move from placed to paid to shipped. Refunds ...';
 *         }
 *     }
 */
class PanelAssistant implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    protected ?Panel $panel = null;

    /** @var class-string<\Filament\Resources\Resource>|null */
    protected ?string $pageResource = null;

    protected ?Model $pageRecord = null;

    protected ?float $temperature = null;

    protected ?int $maxSteps = null;

    protected ?string $model = null;

    /** @var list<string>|null */
    protected ?array $onlyTools = null;

    public function inPanel(?Panel $panel): static
    {
        $this->panel = $panel;

        return $this;
    }

    /**
     * What the user is looking at, so "this order" means something.
     *
     * @param  class-string<\Filament\Resources\Resource>|null  $resource
     */
    public function onPage(?string $resource, ?Model $record = null): static
    {
        $this->pageResource = $resource;
        $this->pageRecord = $record;

        return $this;
    }

    public function instructions(): string
    {
        $sections = array_filter([
            $this->persona(),
            $this->domain(),
            $this->contextSection(),
            $this->capabilitiesSection(),
            $this->rulesSection(),
        ]);

        return implode("\n\n", $sections);
    }

    /**
     * Answer this one with only these tools.
     *
     * A solving phase that says "try the anagrams first" means nothing while
     * the archive is one call away, so a phase can narrow the agent down to
     * the tools its step is about. Null puts every tool back.
     *
     * It only ever narrows: a name that is not among the agent's tools does
     * not add one.
     *
     * @param  list<string>|null  $names
     */
    public function onlyTools(?array $names): static
    {
        $this->onlyTools = $names === null ? null : array_values(array_map(strval(...), $names));

        return $this;
    }

    /**
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        $sources = $this->knowledgeSources();

        $tools = [
            ...($sources === [] ? [] : [new SearchKnowledge($sources), new FetchDocument($sources)]),
            ...app(ResourceToolRegistry::class)->tools($this->panel()),
            ...(RunCode::enabled() ? [new RunCode] : []),
            ...$this->additionalTools(),
        ];

        if ($this->onlyTools === null) {
            return $tools;
        }

        $allowed = $this->onlyTools;

        return array_values(array_filter(
            $tools,
            static fn (Tool $tool): bool => in_array($tool->name(), $allowed, strict: true),
        ));
    }

    /**
     * The provider the panel answers with.
     *
     * laravel/ai asks the agent for `provider()` and `model()` before it falls
     * back to its own `ai.default`, which is how the package's configuration
     * reaches an agent at all: `LaravelAiLanguageModel` is the *retrieval*
     * pipeline's model and is never consulted here. Without these two methods
     * a host that configured `FILAMENT_AI_LLM_PROVIDER=anthropic` still had its panel
     * agent call OpenAI with no key.
     *
     * Null on either leaves laravel/ai's own defaults in charge.
     */
    public function provider(): ?string
    {
        $provider = config('filament-ai.agent.provider') ?? config('filament-ai.llm.provider');

        return blank($provider) ? null : (string) $provider;
    }

    /**
     * Answer this one at a given temperature.
     *
     * Iterative solving runs a wave of attempts side by side and spreads them
     * apart with this: identical temperatures would produce near-identical
     * answers, which is a waste of several model calls.
     */
    public function withTemperature(?float $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function temperature(): ?float
    {
        if ($this->temperature !== null) {
            return $this->temperature;
        }

        $temperature = config('filament-ai.agent.temperature') ?? config('filament-ai.llm.temperature');

        return $temperature === null || $temperature === '' ? null : (float) $temperature;
    }

    /**
     * Cap this one turn's tool round trips, overriding the configured number.
     */
    public function withMaxSteps(?int $steps): static
    {
        $this->maxSteps = $steps;

        return $this;
    }

    /**
     * How many tool round trips one answer may take. Writing a program,
     * running it, reading the error and fixing it is four on its own.
     */
    public function maxSteps(): ?int
    {
        if ($this->maxSteps !== null) {
            return $this->maxSteps;
        }

        $steps = config('filament-ai.agent.max_steps');

        return blank($steps) ? null : (int) $steps;
    }

    /**
     * Answer this one with a given model, where the chat lets a user choose.
     * Null falls back to what is configured.
     */
    public function withModel(?string $model): static
    {
        $this->model = blank($model) ? null : $model;

        return $this;
    }

    public function model(): ?string
    {
        $model = $this->model ?? config('filament-ai.agent.model') ?? config('filament-ai.llm.model');

        return blank($model) ? null : (string) $model;
    }

    protected function persona(): string
    {
        return 'You are the assistant built into this administration panel. You help the signed-in user find information and understand the data they manage, so they can ask instead of navigating.';
    }

    /**
     * What the application is about: its vocabulary, its workflows, what its
     * records mean. Null when the host has not described it.
     */
    protected function domain(): ?string
    {
        return null;
    }

    /**
     * Host-specific tools, added to the derived ones.
     *
     * @return iterable<Tool>
     */
    protected function additionalTools(): iterable
    {
        return [];
    }

    /**
     * Knowledge sources this agent may read. An empty list turns the knowledge
     * tools off; it never means "all of them".
     *
     * @return list<string>
     */
    protected function knowledgeSources(): array
    {
        if (! config('filament-ai.enabled', true) || ! config('filament-ai.agent.knowledge.enabled', true)) {
            return [];
        }

        $keys = app(SourceRegistry::class)->keys();
        $allowed = config('filament-ai.agent.knowledge.sources');

        return $allowed === null ? $keys : array_values(array_intersect($keys, (array) $allowed));
    }

    protected function panel(): ?Panel
    {
        return $this->panel ?? Filament::getCurrentOrDefaultPanel();
    }

    protected function contextSection(): string
    {
        $lines = ['Context:', '- Today is '.now()->toDateString().'.'];

        if (($panel = $this->panel()) !== null) {
            $lines[] = '- Panel: '.$this->plain($this->safely(fn () => $panel->getBrandName()) ?? $panel->getId()).'.';
        }

        if (($user = auth()->user()) !== null) {
            $name = $this->safely(fn () => Filament::getUserName($user)) ?? (string) $user->getAuthIdentifier();
            $lines[] = "- Signed-in user: {$name}.";
        }

        if (($tenant = $this->safely(fn () => Filament::getTenant())) instanceof Model) {
            $lines[] = '- Current tenant: '.($this->safely(fn () => Filament::getTenantName($tenant)) ?? $tenant->getKey()).'. Every record you can read belongs to it.';
        }

        if ($this->pageResource !== null) {
            $resource = $this->pageResource;
            $label = $resource::getModelLabel();

            $lines[] = $this->pageRecord === null
                ? "- The user is on the {$resource::getPluralModelLabel()} list."
                : "- The user is looking at the {$label} \"".$this->plain($resource::getRecordTitle($this->pageRecord))."\" (id {$this->pageRecord->getKey()}). \"This\" or \"it\" most likely refers to that record.";
        }

        return implode("\n", $lines);
    }

    protected function capabilitiesSection(): ?string
    {
        $lines = [];

        if ($this->knowledgeSources() !== []) {
            $lines[] = '- search_knowledge / fetch_document: the indexed documents (manuals, archives, reference texts).';
        }

        if (RunCode::enabled()) {
            $lines[] = '- run_code: a sandbox to write and run a small program in, for anything mechanical -- anagrams, permutations, ciphers, parsing, arithmetic over many values. It reaches nothing of this application.';
        }

        $registry = app(ResourceToolRegistry::class);

        foreach ($registry->blueprints($this->panel()) as $blueprint) {
            $names = array_map(static fn (Tool $tool): string => $tool->name(), $registry->toolsFor($blueprint));

            if ($names === []) {
                continue;
            }

            $lines[] = '- '.implode(' / ', $names).": {$blueprint->pluralLabel}".($blueprint->description === null ? '.' : " -- {$blueprint->description}");
        }

        return $lines === [] ? null : "You can use:\n".implode("\n", $lines);
    }

    protected function rulesSection(): string
    {
        return implode("\n", [
            'Rules:',
            '- Use the tools to look things up. Never invent records, figures or document content; if the tools return nothing, say so.',
            '- Work out anything mechanical with run_code rather than in your head: a program that prints the answer is checkable, a mental calculation is not. Read its output before answering, and fix the program if it errored.',
            '- You change data only through the _create, _edit and _delete tools, and only when the user asked for the change. The user confirms each change in the interface before it runs: call the tool directly with complete arguments instead of asking for confirmation in text. If a change is rejected, do not try it again.',
            '- When no tool can make the change the user wants, tell them where in the panel to make it, linking the record when you have its url.',
            '- When you mention a record that has a url, link it in Markdown.',
            '- When an answer relies on a knowledge passage, cite its marker, e.g. [#1].',
            '- If a tool answers with "Error:", explain the problem plainly; do not retry the same call unchanged.',
            '- Reply in the language the user writes in.',
        ]);
    }

    private function plain(string|Htmlable|null $value): string
    {
        return $value instanceof Htmlable ? trim(strip_tags($value->toHtml())) : (string) $value;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
