<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources\SolveRunResource\Pages;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Murkrow\FilamentAi\Agent\Solving\Strategies;
use Murkrow\FilamentAi\Enums\SolveAttemptStatus;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Filament\Resources\SolveRunResource;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\SolveAttempt;
use Murkrow\FilamentAi\Models\SolveRun;

/**
 * One run, attempt by attempt.
 *
 * The rejected attempts are the point of this page: seeing what was tried and
 * why the judge turned it down is how a badly written goal gets found out.
 */
class ViewSolveRun extends ViewRecord
{
    protected static string $resource = SolveRunResource::class;

    public function getPollingInterval(): ?string
    {
        return $this->getRecord()->status->isRunning()
            ? (string) config('filament-ai.filament.poll_interval', '5s')
            : null;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Run')
                ->columns(3)
                ->schema([
                    TextEntry::make('uuid')->label('Identifier')->copyable()->fontFamily('mono'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(static fn (SolveStatus $state): string => $state->label())
                        ->color(static fn (SolveStatus $state): string => $state->color()),
                    TextEntry::make('waves')
                        ->label('Waves')
                        ->state(static fn (SolveRun $record): string => $record->wave.' / '.$record->waves_total
                            .' ('.$record->attempts_per_wave.' attempts each)'),
                    TextEntry::make('attempts_total')->label('Attempts')->numeric(),
                    TextEntry::make('strategy')
                        ->label('Method')
                        ->state(static fn (SolveRun $record): string => Strategies::for($record->strategy)->label()),
                    TextEntry::make('cost')
                        ->label('Cost')
                        ->state(static fn (SolveRun $record): string => CostCalculator::format($record->cost_micros, 4))
                        ->helperText(static fn (SolveRun $record): string => number_format($record->tokens_used).' tokens, judging included'),
                    TextEntry::make('duration')
                        ->label('Duration')
                        ->state(static fn (SolveRun $record): string => ($record->durationSeconds() ?? 0).'s'),
                ]),

            Section::make('What was asked')
                ->schema([
                    TextEntry::make('goal')->label('Goal'),
                    TextEntry::make('criteria')->label('Accepted when')->placeholder('-'),
                ]),

            Section::make('Outcome')
                ->schema([
                    TextEntry::make('message')
                        ->label('')
                        ->placeholder('Still running.'),
                    TextEntry::make('best')
                        ->label('Best attempt')
                        ->state(static fn (SolveRun $record): ?string => $record->best?->finalAnswer())
                        ->helperText(static fn (SolveRun $record): ?string => $record->best?->reason)
                        ->placeholder('-'),
                ]),

            Section::make('Attempts')
                ->schema([
                    RepeatableEntry::make('attempts')
                        ->label('')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('wave')
                                ->label('Wave')
                                ->state(static fn (SolveAttempt $record): string => "w{$record->wave} #{$record->position}")
                                // The phase is what the wave was told to try;
                                // without it two waves of a staged strategy
                                // read as the same thing twice.
                                ->helperText(static fn (SolveAttempt $record): ?string => $record->phase),
                            TextEntry::make('status')
                                ->badge()
                                ->formatStateUsing(static fn (SolveAttemptStatus $state): string => $state->label())
                                ->color(static fn (SolveAttemptStatus $state): string => $state->color()),
                            TextEntry::make('score')->label('Score')->state(static fn (SolveAttempt $record): string => $record->score.'/100'),
                            TextEntry::make('cost')
                                ->label('Cost')
                                ->state(static fn (SolveAttempt $record): string => CostCalculator::format($record->cost_micros, 4)),
                            TextEntry::make('answer')
                                ->label('Answer')
                                ->state(static fn (SolveAttempt $record): string => $record->finalAnswer())
                                ->columnSpanFull(),
                            TextEntry::make('reason')
                                ->label('Judge')
                                ->placeholder('-')
                                ->columnSpanFull(),
                            TextEntry::make('error')
                                ->label('Error')
                                ->visible(static fn (SolveAttempt $record): bool => filled($record->error))
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }
}
