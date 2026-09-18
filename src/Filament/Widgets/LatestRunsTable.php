<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\IngestionRun;

/**
 * Live view of the most recent runs.
 *
 * Polls on a configurable interval rather than a fixed one: a three-second
 * refresh across several open tabs is real load during a long ingest.
 */
class LatestRunsTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent ingestion runs')
            ->query(fn (): Builder => IngestionRun::query()->latest('id')->limit(5))
            // Only while there is something to watch; see the note in
            // HasAiNavigation::aiPollIntervalWhileRunning().
            ->poll(fn (): ?string => self::pollInterval())
            ->paginated(false)
            ->columns([
                TextColumn::make('source_key')
                    ->label('Source'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (RunStatus $state): string => $state->label())
                    ->color(static fn (RunStatus $state): string => $state->color())
                    ->icon(static fn (RunStatus $state): string => $state->icon()),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(static fn (IngestionRun $record): string => $record->progressPercent().'%'),

                TextColumn::make('chunks_embedded')
                    ->label('Embedded')
                    ->state(static fn (IngestionRun $record): string => number_format($record->chunks_embedded).' / '.number_format($record->chunks_total)),

                TextColumn::make('cost_micros')
                    ->label('Cost')
                    ->state(static fn (IngestionRun $record): string => CostCalculator::format($record->cost_micros, 4)),

                TextColumn::make('created_at')
                    ->label('Started')
                    ->since(),
            ]);
    }

    private static function pollInterval(): ?string
    {
        $interval = config('filament-ai.filament.poll_interval', '5s');

        if ($interval === null || $interval === '') {
            return null;
        }

        return IngestionRun::query()->running()->exists() ? (string) $interval : null;
    }

    public static function canView(): bool
    {
        return (bool) config('filament-ai.filament.pages.dashboard', true);
    }
}
