<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources\DocumentResource\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Murkrow\FilamentAi\Filament\Resources\DocumentResource;
use Murkrow\FilamentAi\Models\Document;

class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('title')->label('Title')->columnSpan(2),
                    TextEntry::make('source_key')->label('Source')->badge(),
                    TextEntry::make('external_id')->label('Identifier in the source')->fontFamily('mono'),
                    TextEntry::make('segment_count')->label('Segments'),
                    TextEntry::make('chunk_count')->label('Chunks'),
                    TextEntry::make('coverage')
                        ->label('Coverage')
                        ->state(static fn (Document $record): string => round($record->coverage() * 100).'%')
                        ->helperText(static fn (Document $record): string => $record->embedded_chunk_count.' of '.$record->chunk_count.' chunks embedded'),
                    TextEntry::make('token_count')->label('Tokens')->numeric(),
                    TextEntry::make('last_ingested_at')->label('Last indexed')->dateTime(),
                ]),

            Section::make('Metadata')
                ->collapsed()
                ->visible(static fn (Document $record): bool => ! empty($record->metadata))
                ->schema([
                    TextEntry::make('metadata')
                        ->label('')
                        ->state(static fn (Document $record): string => (string) json_encode($record->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        ->fontFamily('mono'),
                ]),

            Section::make('Checksums')
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextEntry::make('content_checksum')
                        ->label('Content')
                        ->fontFamily('mono')
                        ->helperText('Changes when the source text changes, which is what makes an incremental re-index cheap.'),
                    TextEntry::make('params_checksum')
                        ->label('Parameters')
                        ->fontFamily('mono')
                        ->helperText('Changes when the chunking parameters or the embedding model change.'),
                ]),

            Section::make('Last error')
                ->visible(static fn (Document $record): bool => $record->last_error !== null)
                ->schema([
                    TextEntry::make('last_error')->label('')->color('danger'),
                ]),
        ]);
    }
}
