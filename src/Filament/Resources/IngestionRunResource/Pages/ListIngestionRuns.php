<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources\IngestionRunResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Murkrow\FilamentAi\Filament\Pages\IngestKnowledge;
use Murkrow\FilamentAi\Filament\Resources\IngestionRunResource;

class ListIngestionRuns extends ListRecords
{
    protected static string $resource = IngestionRunResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        if (! config('rag.filament.pages.ingest', true)) {
            return [];
        }

        return [
            Action::make('ingest')
                ->label('New ingestion')
                ->icon('heroicon-o-plus')
                ->url(IngestKnowledge::getUrl()),
        ];
    }
}
