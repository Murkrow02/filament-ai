<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Murkrow\FilamentAi\Enums\DocumentStatus;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Filament\Concerns\HasAiNavigation;
use Murkrow\FilamentAi\Filament\Resources\DocumentResource\Pages\ListDocuments;
use Murkrow\FilamentAi\Filament\Resources\DocumentResource\Pages\ViewDocument;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Throwable;

/**
 * Browse what is actually indexed.
 *
 * The coverage column is the one to read first: a document at less than 100%
 * has chunks that no search will ever return, which looks from the outside
 * like the corpus simply not containing the answer.
 */
class DocumentResource extends Resource
{
    use HasAiNavigation;

    protected static ?string $model = Document::class;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $modelLabel = 'Indexed document';

    protected static ?string $pluralModelLabel = 'Indexed documents';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::aiSlug('documents');
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
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->description(static fn (Document $record): string => $record->source_key.' #'.$record->external_id)
                    ->wrap(),

                TextColumn::make('segment_count')
                    ->label('Segments')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('chunk_count')
                    ->label('Chunks')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('coverage')
                    ->label('Coverage')
                    ->state(static fn (Document $record): string => round($record->coverage() * 100).'%')
                    ->badge()
                    ->color(static fn (Document $record): string => $record->isFullyEmbedded() ? 'success' : 'warning')
                    ->description(static fn (Document $record): string => $record->embedded_chunk_count.' / '.$record->chunk_count),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (DocumentStatus $state): string => ucfirst($state->value))
                    ->color(static fn (DocumentStatus $state): string => $state->color()),

                TextColumn::make('token_count')
                    ->label('Tokens')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_ingested_at')
                    ->label('Last indexed')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('source_key')
                    ->label('Source')
                    ->options(static fn (): array => app(SourceRegistry::class)->options()),

                SelectFilter::make('status')
                    ->options(array_combine(
                        array_column(DocumentStatus::cases(), 'value'),
                        array_map(static fn (DocumentStatus $s): string => ucfirst($s->value), DocumentStatus::cases()),
                    )),

                Filter::make('incomplete')
                    ->label('Not fully embedded')
                    ->query(static fn ($query) => $query->whereColumn('embedded_chunk_count', '<', 'chunk_count')),
            ])
            ->recordActions([
                Action::make('reindex')
                    ->label('Re-index')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Re-reads this document from its source and embeds anything that changed. Unchanged text keeps its existing vectors.')
                    ->action(static function (Document $record): void {
                        try {
                            FilamentAi::ingest(
                                $record->source_key,
                                ['ids' => $record->external_id],
                                IngestionMode::Full,
                            );

                            Notification::make()->title('Re-index queued')->success()->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Could not queue the re-index')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            DocumentResource\RelationManagers\ChunksRelationManager::class,
        ];
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'view' => ViewDocument::route('/{record}'),
        ];
    }
}
