<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Filament\Concerns\HasRagNavigation;
use Murkrow\FilamentAi\Filament\Resources\IngestionRunResource\Pages\ListIngestionRuns;
use Murkrow\FilamentAi\Filament\Resources\IngestionRunResource\Pages\ViewIngestionRun;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\IngestionRun;

/**
 * Monitoring surface for ingestion.
 *
 * Read-only by design -- runs are launched from the ingestion page, never
 * created by hand -- with the two operational actions that matter: stop a run
 * that is doing the wrong thing, and retry the jobs that failed.
 */
class IngestionRunResource extends Resource
{
    use HasRagNavigation;

    protected static ?string $model = IngestionRun::class;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $modelLabel = 'Ingestion run';

    protected static ?string $pluralModelLabel = 'Ingestion runs';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::ragSlug('ingestion-runs');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return static::canAccessRag();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll(fn (): ?string => static::ragPollIntervalWhileRunning())
            ->columns([
                TextColumn::make('uuid')
                    ->label('Run')
                    ->formatStateUsing(static fn (string $state): string => substr($state, 0, 8))
                    ->copyable()
                    ->copyableState(static fn (string $state): string => $state)
                    ->fontFamily('mono'),

                TextColumn::make('source_key')
                    ->label('Source')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('mode')
                    ->label('Mode')
                    ->formatStateUsing(static fn ($state): string => $state->label()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (RunStatus $state): string => $state->label())
                    ->color(static fn (RunStatus $state): string => $state->color())
                    ->icon(static fn (RunStatus $state): string => $state->icon()),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(static fn (IngestionRun $record): string => $record->progressPercent().'%')
                    ->description(static fn (IngestionRun $record): string => $record->documents_done.' / '.$record->documents_total.' documents'),

                TextColumn::make('chunks_embedded')
                    ->label('Chunks')
                    ->state(static fn (IngestionRun $record): string => number_format($record->chunks_embedded).' / '.number_format($record->chunks_total))
                    ->description(static fn (IngestionRun $record): string => $record->chunks_created.' new, '.$record->chunks_reused.' reused'),

                TextColumn::make('tokens_used')
                    ->label('Tokens')
                    ->numeric()
                    ->toggleable(),

                TextColumn::make('cost_micros')
                    ->label('Cost')
                    ->state(static fn (IngestionRun $record): string => CostCalculator::format($record->cost_micros, 4)),

                TextColumn::make('created_at')
                    ->label('Started')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(
                        array_column(RunStatus::cases(), 'value'),
                        array_map(static fn (RunStatus $s): string => $s->label(), RunStatus::cases()),
                    )),

                SelectFilter::make('source_key')
                    ->label('Source')
                    ->options(static fn (): array => app(\Murkrow\FilamentAi\Sources\SourceRegistry::class)->options()),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('The current job finishes, then the rest of the batch is dropped. Work already done is kept.')
                    ->visible(static fn (IngestionRun $record): bool => $record->status->isRunning())
                    ->action(static function (IngestionRun $record): void {
                        $record->cancel();

                        Notification::make()->title('Run cancelled')->warning()->send();
                    }),

                Action::make('retryFailed')
                    ->label('Retry failed')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(static fn (IngestionRun $record): bool => $record->failedJobs() > 0)
                    ->action(static function (IngestionRun $record): void {
                        $batch = $record->currentBatch();

                        if ($batch === null) {
                            Notification::make()->title('That batch has expired')->danger()->send();

                            return;
                        }

                        Artisan::call('queue:retry-batch', ['id' => $batch->id]);

                        Notification::make()->title('Failed jobs re-queued')->success()->send();
                    }),
            ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListIngestionRuns::route('/'),
            'view' => ViewIngestionRun::route('/{record}'),
        ];
    }
}
