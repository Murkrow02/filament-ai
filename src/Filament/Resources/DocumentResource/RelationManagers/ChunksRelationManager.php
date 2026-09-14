<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources\DocumentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * The chunks a document was split into.
 *
 * Worth looking at when retrieval misbehaves: chunk boundaries and page ranges
 * explain most "why did it not find that" questions faster than any metric.
 */
class ChunksRelationManager extends RelationManager
{
    protected static string $relationship = 'chunks';

    protected static ?string $title = 'Chunks';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('ordinal')
            ->columns([
                TextColumn::make('ordinal')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('position')
                    ->label('Position')
                    ->state(function (Chunk $record): string {
                        $sources = app(SourceRegistry::class);

                        return $sources->has($record->source_key)
                            ? $sources->get($record->source_key)->positionLabel($record->position_start, $record->position_end)
                            : $record->position_start.'-'.$record->position_end;
                    })
                    ->badge()
                    ->color(static fn (Chunk $record): string => $record->position_end > $record->position_start ? 'info' : 'gray')
                    ->tooltip(static fn (Chunk $record): ?string => $record->position_end > $record->position_start
                        ? 'This chunk spans a segment boundary'
                        : null),

                TextColumn::make('token_count')
                    ->label('Tokens')
                    ->numeric()
                    ->sortable(),

                IconColumn::make('embedded_at')
                    ->label('Embedded')
                    ->boolean()
                    ->getStateUsing(static fn (Chunk $record): bool => $record->isEmbedded()),

                TextColumn::make('embedding_model')
                    ->label('Model')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('content')
                    ->label('Text')
                    ->limit(120)
                    ->wrap()
                    ->tooltip(static fn (Chunk $record): string => $record->snippet(600)),
            ])
            ->filters([
                Filter::make('pending')
                    ->label('Awaiting embedding')
                    ->query(static fn ($query) => $query->whereNull('embedded_at')),

                Filter::make('spanning')
                    ->label('Spanning a boundary')
                    ->query(static fn ($query) => $query->whereColumn('position_end', '>', 'position_start')),
            ]);
    }
}
