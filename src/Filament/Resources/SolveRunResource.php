<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Filament\Concerns\HasAiNavigation;
use Murkrow\FilamentAi\Filament\Resources\SolveRunResource\Pages\ListSolveRuns;
use Murkrow\FilamentAi\Filament\Resources\SolveRunResource\Pages\ViewSolveRun;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\SolveRun;

/**
 * The iterative runs: what was asked, how many attempts it took, what it cost
 * and why it stopped.
 *
 * Read-only by design. A run is started from the application, never from a
 * list page: it spends money per row.
 */
class SolveRunResource extends Resource
{
    use HasAiNavigation;

    protected static ?string $model = SolveRun::class;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $modelLabel = 'Solve run';

    protected static ?string $pluralModelLabel = 'Solve runs';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::aiSlug('solve-runs');
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
            // Nothing polls unless something is actually running.
            ->poll(fn (): ?string => SolveRun::query()->running()->exists() ? static::aiPollInterval() : null)
            ->columns([
                TextColumn::make('uuid')
                    ->label('Run')
                    ->formatStateUsing(static fn (string $state): string => substr($state, 0, 8))
                    ->copyable()
                    ->copyableState(static fn (string $state): string => $state)
                    ->fontFamily('mono'),
                TextColumn::make('goal')
                    ->label('Goal')
                    ->formatStateUsing(static fn (string $state): string => Str::limit($state, 60))
                    ->tooltip(static fn (SolveRun $record): string => $record->goal)
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (SolveStatus $state): string => $state->label())
                    ->color(static fn (SolveStatus $state): string => $state->color()),
                TextColumn::make('wave')
                    ->label('Waves')
                    ->state(static fn (SolveRun $record): string => $record->wave.' / '.$record->waves_total)
                    ->description(static fn (SolveRun $record): string => $record->attempts_total.' attempts'),
                TextColumn::make('best_score')
                    ->label('Best')
                    ->state(static fn (SolveRun $record): string => $record->best_score.'/100')
                    ->description(static fn (SolveRun $record): ?string => $record->best === null
                        ? null
                        : Str::limit($record->best->finalAnswer(), 40)),
                TextColumn::make('cost_micros')
                    ->label('Cost')
                    ->state(static fn (SolveRun $record): string => CostCalculator::format($record->cost_micros, 4))
                    ->description(static fn (SolveRun $record): string => number_format($record->tokens_used).' tokens'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(
                        array_column(SolveStatus::cases(), 'value'),
                        array_map(static fn (SolveStatus $status): string => $status->label(), SolveStatus::cases()),
                    )),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Attempts already running finish and are kept; no further wave starts.')
                    ->visible(static fn (SolveRun $record): bool => $record->status->isRunning())
                    ->action(static function (SolveRun $record): void {
                        $record->cancel();

                        Notification::make()->title('Run cancelled')->warning()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSolveRuns::route('/'),
            'view' => ViewSolveRun::route('/{record}'),
        ];
    }
}
