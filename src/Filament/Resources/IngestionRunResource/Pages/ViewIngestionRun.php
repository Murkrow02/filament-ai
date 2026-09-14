<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources\IngestionRunResource\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Filament\Resources\IngestionRunResource;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\IngestionRun;

class ViewIngestionRun extends ViewRecord
{
    protected static string $resource = IngestionRunResource::class;

    /**
     * Refresh while the run is live, then stop: a finished run is static and
     * polling it forever is pure database load.
     */
    public function getPollingInterval(): ?string
    {
        /** @var IngestionRun $record */
        $record = $this->getRecord();

        return $record->status->isRunning()
            ? (string) config('rag.filament.poll_interval', '5s')
            : null;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Run')
                ->columns(3)
                ->schema([
                    TextEntry::make('uuid')->label('Identifier')->copyable()->fontFamily('mono'),
                    TextEntry::make('source_key')->label('Source')->badge(),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(static fn (RunStatus $state): string => $state->label())
                        ->color(static fn (RunStatus $state): string => $state->color()),
                    TextEntry::make('mode')->formatStateUsing(static fn ($state): string => $state->label()),
                    TextEntry::make('embedding_model')->label('Embedding model'),
                    TextEntry::make('vector_driver')->label('Vector driver'),
                    TextEntry::make('started_at')->dateTime(),
                    TextEntry::make('finished_at')->dateTime()->placeholder('-'),
                    TextEntry::make('duration')
                        ->label('Duration')
                        ->state(static fn (IngestionRun $record): string => ($record->durationSeconds() ?? 0).'s'),
                ]),

            Section::make('Progress')
                ->columns(4)
                ->schema([
                    TextEntry::make('documents')
                        ->label('Documents')
                        ->state(static fn (IngestionRun $r): string => "{$r->documents_done} done / {$r->documents_skipped} skipped / {$r->documents_failed} failed of {$r->documents_total}"),
                    TextEntry::make('chunks')
                        ->label('Chunks')
                        ->state(static fn (IngestionRun $r): string => "{$r->chunks_created} created / {$r->chunks_reused} reused / {$r->chunks_deleted} deleted"),
                    TextEntry::make('embedded')
                        ->label('Embedded')
                        ->state(static fn (IngestionRun $r): string => number_format($r->chunks_embedded).' / '.number_format($r->chunks_total)),
                    TextEntry::make('cost')
                        ->label('Cost')
                        ->state(static fn (IngestionRun $r): string => CostCalculator::format($r->cost_micros, 4))
                        ->helperText(static fn (IngestionRun $r): string => number_format($r->tokens_used).' tokens over '.number_format($r->api_calls).' API calls'),
                ]),

            Section::make('Chunking parameters')
                ->collapsed()
                ->schema([
                    TextEntry::make('chunking_params')
                        ->label('')
                        ->state(static fn (IngestionRun $r): string => (string) json_encode($r->chunking_params, JSON_PRETTY_PRINT))
                        ->fontFamily('mono'),
                ]),

            Section::make('Last error')
                ->visible(static fn (IngestionRun $r): bool => $r->last_error !== null)
                ->schema([
                    TextEntry::make('last_error')->label('')->color('danger'),
                ]),
        ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel run')
                ->color('warning')
                ->icon('heroicon-o-no-symbol')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->status->isRunning())
                ->action(function (): void {
                    /** @var IngestionRun $record */
                    $record = $this->getRecord();
                    $record->cancel();

                    Notification::make()->title('Run cancelled')->warning()->send();
                }),
        ];
    }
}
