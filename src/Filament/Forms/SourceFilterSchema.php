<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Forms;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Murkrow\FilamentAi\Contracts\KnowledgeSource;
use Murkrow\FilamentAi\Contracts\SourceFilter;
use Murkrow\FilamentAi\Sources\Filters\BooleanFilter;
use Murkrow\FilamentAi\Sources\Filters\DateRangeFilter;
use Murkrow\FilamentAi\Sources\Filters\IdsFilter;
use Murkrow\FilamentAi\Sources\Filters\InFilter;
use Murkrow\FilamentAi\Sources\Filters\NullFilter;
use Murkrow\FilamentAi\Sources\Filters\RangeFilter;

/**
 * Turns a source's filters into form fields.
 *
 * The mapping lives here, in the optional Filament layer, so a filter class
 * stays a plain query constraint: the same declaration drives
 * `rag:ingest --filter=` and this form, and adding a filter to a source needs
 * no Filament code at all. A filter class this does not recognise degrades to
 * a text input rather than disappearing.
 */
final class SourceFilterSchema
{
    /**
     * @return array<int, \Filament\Schemas\Components\Component|Field>
     */
    public static function for(KnowledgeSource $source): array
    {
        $fields = [];

        foreach ($source->filterSet()->all() as $filter) {
            $fields[] = self::field($filter);
        }

        return $fields;
    }

    private static function field(SourceFilter $filter): mixed
    {
        $label = $filter->label();
        $statePath = "filters.{$filter->name()}";

        return match (true) {
            $filter instanceof IdsFilter => TextInput::make($statePath)
                ->label($label)
                ->placeholder('e.g. 12, 15, 31')
                ->helperText('Comma-separated identifiers.'),

            $filter instanceof RangeFilter => Grid::make(2)->schema([
                TextInput::make($statePath.'.from')->label($label.' from')->numeric(),
                TextInput::make($statePath.'.to')->label($label.' to')->numeric(),
            ]),

            $filter instanceof DateRangeFilter => Grid::make(2)->schema([
                TextInput::make($statePath.'.from')->label($label.' from')->type('date'),
                TextInput::make($statePath.'.to')->label($label.' to')->type('date'),
            ]),

            $filter instanceof BooleanFilter,
            $filter instanceof NullFilter => Toggle::make($statePath)
                ->label($label)
                ->default((bool) $filter->default()),

            $filter instanceof InFilter => Select::make($statePath)
                ->label($label)
                ->multiple()
                ->options($filter->options()),

            default => TextInput::make($statePath)->label($label),
        };
    }
}
