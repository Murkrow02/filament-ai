<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Contracts\SourceFilter;
use Murkrow\FilamentAi\Data\DocumentDraft;
use Murkrow\FilamentAi\Data\Segment;
use Murkrow\FilamentAi\Exceptions\InvalidSourceConfigurationException;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;

/**
 * A knowledge source whose documents are *groups* of rows.
 *
 * `EloquentSource` maps one model row to one document, which is right when a
 * row carries a document's worth of text. It is wrong for a table of thousands
 * of short rows -- a gazetteer, a glossary, a term list -- where one row is a
 * few tokens: indexing each as its own document produces thousands of
 * near-empty vectors that all look alike.
 *
 * This base groups the rows instead. A grouping expression's distinct values
 * become the documents, and the rows inside a group become its ordered
 * segments, so the chunker packs many rows into each chunk:
 *
 *     protected function groupBy(): string    { return 'upper(substr(name, 1, 1))'; }
 *     protected function textColumn(): string { return 'name'; }
 *
 * Filters select which *documents* a run covers, exactly as they do for
 * `EloquentSource`: a group matched by a filter is ingested whole, not reduced
 * to the rows that matched.
 */
abstract class GroupedEloquentSource implements KnowledgeSource
{
    private ?FilterSet $filterSet = null;

    abstract public function key(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * SQL expression whose distinct values become documents -- a column name,
     * or something like `upper(substr(name, 1, 1))`.
     *
     * It is interpolated into the query, so it must come from the source class
     * itself and never from a request: this is code, not input.
     */
    abstract protected function groupBy(): string;

    /**
     * The column on each row holding the text of one segment.
     */
    abstract protected function textColumn(): string;

    public function label(): string
    {
        return Str::headline($this->key());
    }

    public function icon(): ?string
    {
        return null;
    }

    /**
     * How rows are ordered inside a document. It has to be deterministic, or
     * chunk boundaries move between runs and every chunk re-embeds.
     */
    protected function orderBy(): string
    {
        return $this->textColumn();
    }

    protected function batchSize(): int
    {
        return 500;
    }

    protected function documentTitle(string $group): string
    {
        return $group;
    }

    /**
     * @return array<string, mixed>
     */
    protected function documentMetadata(string $group, int $entries): array
    {
        return ['entries' => $entries];
    }

    /**
     * @return iterable<SourceFilter>
     */
    protected function filters(): iterable
    {
        return [];
    }

    protected function positionLabels(): PositionLabels
    {
        return new PositionLabels;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    protected function scope(Builder $query): void {}

    public function url(Document $document, ?Chunk $chunk = null): ?string
    {
        return null;
    }

    public function chunkingOverrides(): ChunkingOverrides
    {
        return new ChunkingOverrides;
    }

    final public function filterSet(): FilterSet
    {
        return $this->filterSet ??= FilterSet::make($this->filters());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, DocumentDraft>
     */
    public function documents(array $filters = []): LazyCollection
    {
        $expression = $this->groupBy();

        $query = $this->query($filters)->toBase()
            ->selectRaw("{$expression} as rag_group, count(*) as rag_entries")
            ->groupByRaw($expression)
            ->orderByRaw($expression);

        return $query->cursor()
            ->filter(static fn (object $row): bool => $row->rag_group !== null)
            ->map(fn (object $row): DocumentDraft => $this->toDraft(
                (string) $row->rag_group,
                (int) $row->rag_entries,
            ));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function countDocuments(array $filters = []): int
    {
        $expression = $this->groupBy();

        $row = $this->query($filters)->toBase()
            ->selectRaw("count(distinct {$expression}) as aggregate")
            ->first();

        return (int) ($row->aggregate ?? 0);
    }

    public function findDocument(string $externalId): ?DocumentDraft
    {
        $entries = $this->groupQuery($externalId)->count();

        return $entries === 0 ? null : $this->toDraft($externalId, $entries);
    }

    /**
     * Positions are ordinals within the group, assigned as the rows stream.
     *
     * Inserting a row in the middle of a group therefore shifts the positions
     * after it, and the chunks covering them re-embed on the next run. That is
     * the price of a stable, human-readable "entry 120" citation on a table
     * whose rows carry no position of their own.
     *
     * @return Generator<int, Segment>
     */
    public function segments(string $externalId): Generator
    {
        $textColumn = $this->textColumn();
        $position = 0;

        foreach ($this->groupQuery($externalId)->lazy($this->batchSize()) as $row) {
            yield new Segment(
                position: ++$position,
                text: (string) ($row->getAttribute($textColumn) ?? ''),
            );
        }
    }

    public function positionLabel(int $start, int $end): string
    {
        return $this->positionLabels()->render($start, $end);
    }

    /**
     * The rows of one document, in order.
     *
     * @return Builder<covariant Model>
     */
    protected function groupQuery(string $group): Builder
    {
        $query = $this->newQuery();

        $this->scope($query);

        return $query
            ->whereRaw("{$this->groupBy()} = ?", [$group])
            ->orderBy($this->orderBy());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<covariant Model>
     */
    protected function query(array $filters = []): Builder
    {
        $query = $this->newQuery();

        $this->scope($query);
        $this->filterSet()->applyTo($query, $filters);

        return $query;
    }

    /**
     * @return Builder<covariant Model>
     */
    protected function newQuery(): Builder
    {
        $class = $this->model();

        if (! is_subclass_of($class, Model::class)) {
            throw InvalidSourceConfigurationException::notAModel($this->key(), $class);
        }

        return $class::query();
    }

    protected function toDraft(string $group, int $entries): DocumentDraft
    {
        return new DocumentDraft(
            sourceKey: $this->key(),
            externalId: $group,
            title: $this->documentTitle($group),
            metadata: $this->documentMetadata($group, $entries),
        );
    }
}
