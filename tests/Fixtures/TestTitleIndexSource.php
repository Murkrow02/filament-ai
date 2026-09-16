<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Murkrow\FilamentAi\Sources\Filter;
use Murkrow\FilamentAi\Sources\GroupedEloquentSource;
use Murkrow\FilamentAi\Sources\PositionLabels;

/**
 * Stands in for a host's list-shaped data: one document per initial letter,
 * one segment per row. `upper(substr(...))` is chosen because SQLite and
 * PostgreSQL both understand it, so the same fixture exercises both suites.
 */
class TestTitleIndexSource extends GroupedEloquentSource
{
    public function key(): string
    {
        return 'titles';
    }

    public function label(): string
    {
        return 'Title index';
    }

    protected function model(): string
    {
        return TestBook::class;
    }

    protected function groupBy(): string
    {
        return 'upper(substr(title, 1, 1))';
    }

    protected function textColumn(): string
    {
        return 'title';
    }

    protected function documentTitle(string $group): string
    {
        return "Titles - {$group}";
    }

    protected function filters(): iterable
    {
        return [
            Filter::like('title', label: 'Title contains'),
        ];
    }

    protected function positionLabels(): PositionLabels
    {
        return new PositionLabels('Entries :start-:end', 'Entry :start');
    }
}
