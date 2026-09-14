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
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Murkrow\FilamentAi\Agent\Tools\FetchDocument;
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
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        $sources = $this->knowledgeSources();

        return [
            ...($sources === [] ? [] : [new SearchKnowledge($sources), new FetchDocument($sources)]),
            ...app(ResourceToolRegistry::class)->tools($this->panel()),
            ...$this->additionalTools(),
        ];
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
        if (! config('rag.enabled', true) || ! config('rag.agent.knowledge.enabled', true)) {
            return [];
        }

        $keys = app(SourceRegistry::class)->keys();
        $allowed = config('rag.agent.knowledge.sources');

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

        foreach (app(ResourceToolRegistry::class)->blueprints($this->panel()) as $blueprint) {
            $abilities = array_map(
                static fn (string $ability): string => $blueprint->toolPrefix.'_'.$ability,
                array_values(array_intersect(AgentTools::ABILITIES, $blueprint->abilities)),
            );

            $lines[] = '- '.implode(' / ', $abilities).": {$blueprint->pluralLabel}".($blueprint->description === null ? '.' : " -- {$blueprint->description}");
        }

        return $lines === [] ? null : "You can read:\n".implode("\n", $lines);
    }

    protected function rulesSection(): string
    {
        return implode("\n", [
            'Rules:',
            '- Use the tools to look things up. Never invent records, figures or document content; if the tools return nothing, say so.',
            '- You can read data but not change it. If the user asks for a change, tell them where in the panel to make it, linking the record when you have its url.',
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
