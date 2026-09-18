<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Murkrow\FilamentAi\Enums\QueryChannel;
use Murkrow\FilamentAi\Filament\Concerns\HasAiNavigation;
use Murkrow\FilamentAi\Filament\Resources\QueryResource\Pages\ListQueries;
use Murkrow\FilamentAi\Filament\Resources\QueryResource\Pages\ViewQuery;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\QueryLog;

/**
 * The question log.
 *
 * Doubles as the evaluation set. Refused questions are the most valuable rows
 * in the whole system: each one is either a gap in the corpus or a retrieval
 * failure, and they are the only place the difference shows up honestly.
 */
class QueryResource extends Resource
{
    use HasAiNavigation;

    protected static ?string $model = QueryLog::class;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $modelLabel = 'Question';

    protected static ?string $pluralModelLabel = 'Questions';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::aiSlug('queries');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return static::canAccessAi();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('question')
                    ->label('Question')
                    ->limit(80)
                    ->wrap()
                    ->searchable(),

                IconColumn::make('refused')
                    ->label('Answered')
                    ->boolean()
                    ->getStateUsing(static fn (QueryLog $record): bool => ! $record->refused)
                    ->trueColor('success')
                    ->falseColor('warning'),

                TextColumn::make('retrieved_count')
                    ->label('Passages')
                    ->numeric()
                    ->description(static fn (QueryLog $record): string => $record->top_score === null
                        ? '-'
                        : 'top '.number_format($record->top_score, 3)),

                TextColumn::make('channel')
                    ->label('Channel')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('latency_ms')
                    ->label('Latency')
                    ->state(static fn (QueryLog $record): string => ($record->latency_ms ?? 0).' ms')
                    ->description(static fn (QueryLog $record): string => ($record->retrieval_ms ?? 0).' ms retrieval')
                    ->sortable(),

                TextColumn::make('cost_micros')
                    ->label('Cost')
                    ->state(static fn (QueryLog $record): string => CostCalculator::format($record->cost_micros, 5))
                    ->toggleable(),

                TextColumn::make('feedback')
                    ->label('Rating')
                    ->badge()
                    ->formatStateUsing(static fn (?int $state): string => match ($state) {
                        1 => 'Good',
                        -1 => 'Bad',
                        default => '-',
                    })
                    ->color(static fn (?int $state): string => match ($state) {
                        1 => 'success',
                        -1 => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('refused')
                    ->label('Refused only')
                    ->query(static fn ($query) => $query->where('refused', true)),

                SelectFilter::make('channel')
                    ->options(array_combine(
                        array_column(QueryChannel::cases(), 'value'),
                        array_map(static fn (QueryChannel $c): string => ucfirst($c->value), QueryChannel::cases()),
                    )),

                Filter::make('rated_bad')
                    ->label('Rated bad')
                    ->query(static fn ($query) => $query->where('feedback', -1)),
            ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListQueries::route('/'),
            'view' => ViewQuery::route('/{record}'),
        ];
    }
}
