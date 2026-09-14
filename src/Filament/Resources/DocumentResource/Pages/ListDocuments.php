<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources\DocumentResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Murkrow\FilamentAi\Filament\Resources\DocumentResource;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;
}
