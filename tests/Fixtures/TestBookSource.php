<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Murkrow\FilamentAi\Sources\EloquentSource;
use Murkrow\FilamentAi\Sources\Filter;
use Murkrow\FilamentAi\Sources\PositionLabels;
use Murkrow\FilamentAi\Sources\SegmentMap;

/**
 * The source every feature test ingests from.
 *
 * It stands in for a host application's own source class, which is the point:
 * if this is enough to exercise the whole pipeline, nothing in the package
 * needs to know what a Book is.
 */
class TestBookSource extends EloquentSource
{
    public function key(): string
    {
        return 'books';
    }

    public function label(): string
    {
        return 'Books';
    }

    protected function model(): string
    {
        return TestBook::class;
    }

    protected function titleColumn(): ?string
    {
        return 'title';
    }

    protected function metadata(): array
    {
        return ['author'];
    }

    protected function segmentMap(): SegmentMap
    {
        return SegmentMap::relation('pages', text: 'content', position: 'number', batchSize: 50);
    }

    protected function filters(): iterable
    {
        return [
            Filter::ids('ids', 'id', label: 'Specific IDs'),
            Filter::range('id_range', 'id', label: 'ID range'),
            Filter::like('title', label: 'Title contains'),
            Filter::boolean('bad_ocr', label: 'Include badly scanned books', default: false),
        ];
    }

    protected function positionLabels(): PositionLabels
    {
        return new PositionLabels('Pages :start-:end', 'Page :start');
    }
}
