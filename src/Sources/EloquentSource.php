<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Contracts\SourceFilter;
use Murkrow\FilamentAi\Data\DocumentDraft;
use Murkrow\FilamentAi\Data\Segment;
use Murkrow\FilamentAi\Exceptions\InvalidSourceConfigurationException;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Support\Text;

/**
 * Base class for a knowledge source backed by an Eloquent model.
 *
 * A host application declares a source by extending this and answering three
 * questions -- which model, under which key, and where the ordered text lives:
 *
 *     final class BookSource extends EloquentSource
 *     {
 *         public function key(): string { return 'books'; }
 *
 *         protected function model(): string { return Book::class; }
 *
 *         protected function segmentMap(): SegmentMap
 *         {
 *             return SegmentMap::relation('pages', text: 'content', position: 'number');
 *         }
 *     }
 *
 * Everything else has a working default and is overridden only when it differs.
 * The package still contains no reference to any host model: the mapping lives
 * in the host's own class, which is why this file names none of them.
 */
abstract class EloquentSource implements KnowledgeSource
{
    private ?FilterSet $filterSet = null;

    /**
     * Identifier used by `rag:ingest`, the Filament form and `source_key` on
     * every stored document. Changing it orphans the rows already indexed.
     */
    abstract public function key(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    abstract protected function segmentMap(): SegmentMap;

    public function label(): string
    {
        return Str::headline($this->key());
    }

    public function icon(): ?string
    {
        return null;
    }

    /**
     * The column whose value becomes `external_id`. It has to be stable: it is
     * how a re-run recognises a document it has already ingested.
     */
    protected function keyColumn(): string
    {
        return 'id';
    }

    protected function titleColumn(): ?string
    {
        return null;
    }

    /**
     * Attributes copied onto the document, either as a list of column names or
     * as `alias => Closure(Model): mixed` for computed values.
     *
     * @return array<int|string, string|\Closure(Model): mixed>
     */
    protected function metadata(): array
    {
        return [];
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
     * Narrow what the source exposes at all, regardless of the caller's
     * filters -- unpublished rows, other tenants, soft-hidden records.
     *
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
        return $this->query($filters)
            ->lazyById(500, $this->keyColumn())
            ->map(fn (Model $model): DocumentDraft => $this->toDraft($model));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function countDocuments(array $filters = []): int
    {
        return $this->query($filters)->count();
    }

    public function findDocument(string $externalId): ?DocumentDraft
    {
        $model = $this->find($externalId);

        return $model === null ? null : $this->toDraft($model);
    }

    /**
     * Streams the document's segments in position order.
     *
     * Uses a lazy cursor so a document with thousands of segments never has to
     * be materialised: the chunker only ever holds a window.
     *
     * @return Generator<int, Segment>
     */
    public function segments(string $externalId): Generator
    {
        $model = $this->find($externalId);

        if ($model === null) {
            return;
        }

        $map = $this->segmentMap();

        if (! $map->isRelation()) {
            $text = (string) ($model->getAttribute($map->text) ?? '');

            if ($text !== '') {
                yield new Segment(position: 1, text: $text);
            }

            return;
        }

        $relationName = (string) $map->relation;

        if (! method_exists($model, $relationName)) {
            throw InvalidSourceConfigurationException::notARelation($this->key(), $relationName, $model::class);
        }

        /** @var Relation<Model, Model, mixed> $relation */
        $relation = $model->{$relationName}();

        $query = $relation->getQuery()
            ->select([$map->position, $map->text])
            ->orderBy($map->orderBy);

        foreach ($query->lazy($map->batchSize) as $row) {
            yield new Segment(
                position: (int) $row->getAttribute($map->position),
                text: (string) ($row->getAttribute($map->text) ?? ''),
            );
        }
    }

    public function positionLabel(int $start, int $end): string
    {
        return $this->positionLabels()->render($start, $end);
    }

    /**
     * The document-level query with the source's own scope and the caller's
     * filters applied.
     *
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

    protected function find(string $externalId): ?Model
    {
        return $this->newQuery()->where($this->keyColumn(), $externalId)->first();
    }

    protected function title(Model $model): ?string
    {
        $column = $this->titleColumn();

        if ($column === null) {
            return null;
        }

        $title = (string) ($model->getAttribute($column) ?? '');

        return $title === '' ? null : $title;
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataFor(Model $model): array
    {
        $metadata = [];

        foreach ($this->metadata() as $alias => $attribute) {
            if ($attribute instanceof \Closure) {
                $metadata[(string) $alias] = $attribute($model);

                continue;
            }

            $metadata[is_string($alias) ? $alias : (string) $attribute] = $model->getAttribute((string) $attribute);
        }

        return $metadata;
    }

    protected function toDraft(Model $model): DocumentDraft
    {
        return new DocumentDraft(
            sourceKey: $this->key(),
            externalId: Text::externalId($model->getAttribute($this->keyColumn())),
            title: $this->title($model),
            metadata: $this->metadataFor($model),
        );
    }
}
