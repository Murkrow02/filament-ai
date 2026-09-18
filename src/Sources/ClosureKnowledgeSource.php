<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

use Closure;
use Generator;
use Illuminate\Support\LazyCollection;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Contracts\SourceFilter;
use Murkrow\FilamentAi\Data\DocumentDraft;
use Murkrow\FilamentAi\Data\Segment;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;

/**
 * Escape hatch for knowledge that is not an Eloquent relation: an API, a
 * filesystem tree, a view, a union of several tables.
 *
 *     FilamentAi::source('handbook')
 *         ->setLabel('Handbook')
 *         ->loadDocumentsUsing(fn (array $filters) => LazyCollection::make(...))
 *         ->loadSegmentsUsing(fn (string $id) => yield new Segment(1, '...'))
 *         ->register();
 *
 * Anything with a schema worth naming belongs in a class extending
 * `EloquentSource` instead; this is for the one-off.
 */
final class ClosureKnowledgeSource implements KnowledgeSource
{
    private ?Closure $documentsCallback = null;

    private ?Closure $segmentsCallback = null;

    private ?Closure $findCallback = null;

    private ?Closure $countCallback = null;

    private ?Closure $urlCallback = null;

    private string $label;

    private ?string $icon = null;

    private PositionLabels $positionLabels;

    private FilterSet $filters;

    private ChunkingOverrides $chunking;

    /**
     * @param  Closure(KnowledgeSource): void|null  $onRegister
     */
    public function __construct(
        private readonly string $key,
        private readonly ?Closure $onRegister = null,
    ) {
        $this->label = ucfirst($key);
        $this->positionLabels = new PositionLabels;
        $this->filters = new FilterSet;
        $this->chunking = new ChunkingOverrides;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function icon(): ?string
    {
        return $this->icon;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * @param  Closure(array<string, mixed>): LazyCollection<int, DocumentDraft>  $callback
     */
    public function loadDocumentsUsing(Closure $callback): self
    {
        $this->documentsCallback = $callback;

        return $this;
    }

    /**
     * @param  Closure(string): Generator<int, Segment>  $callback
     */
    public function loadSegmentsUsing(Closure $callback): self
    {
        $this->segmentsCallback = $callback;

        return $this;
    }

    public function findUsing(Closure $callback): self
    {
        $this->findCallback = $callback;

        return $this;
    }

    public function countUsing(Closure $callback): self
    {
        $this->countCallback = $callback;

        return $this;
    }

    public function urlUsing(Closure $callback): self
    {
        $this->urlCallback = $callback;

        return $this;
    }

    public function withFilters(SourceFilter ...$filters): self
    {
        $this->filters = new FilterSet(...$filters);

        return $this;
    }

    public function withChunking(ChunkingOverrides $overrides): self
    {
        $this->chunking = $overrides;

        return $this;
    }

    public function withPositionLabels(string $range, string $single): self
    {
        $this->positionLabels = new PositionLabels($range, $single);

        return $this;
    }

    /**
     * Hand the finished source to the registry it came from.
     */
    public function register(): self
    {
        if ($this->onRegister !== null) {
            ($this->onRegister)($this);
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, DocumentDraft>
     */
    public function documents(array $filters = []): LazyCollection
    {
        if ($this->documentsCallback === null) {
            return LazyCollection::empty();
        }

        /** @var LazyCollection<int, DocumentDraft> */
        return ($this->documentsCallback)($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function countDocuments(array $filters = []): int
    {
        if ($this->countCallback !== null) {
            return (int) ($this->countCallback)($filters);
        }

        return $this->documents($filters)->count();
    }

    public function findDocument(string $externalId): ?DocumentDraft
    {
        if ($this->findCallback !== null) {
            /** @var DocumentDraft|null */
            return ($this->findCallback)($externalId);
        }

        return $this->documents()
            ->first(static fn (DocumentDraft $draft): bool => $draft->externalId === $externalId);
    }

    /**
     * @return Generator<int, Segment>
     */
    public function segments(string $externalId): Generator
    {
        if ($this->segmentsCallback === null) {
            return;
        }

        yield from ($this->segmentsCallback)($externalId);
    }

    public function filterSet(): FilterSet
    {
        return $this->filters;
    }

    public function positionLabel(int $start, int $end): string
    {
        return $this->positionLabels->render($start, $end);
    }

    public function url(Document $document, ?Chunk $chunk = null): ?string
    {
        if ($this->urlCallback === null) {
            return null;
        }

        $url = ($this->urlCallback)($document, $chunk);

        return $url === null ? null : (string) $url;
    }

    public function chunkingOverrides(): ChunkingOverrides
    {
        return $this->chunking;
    }
}
