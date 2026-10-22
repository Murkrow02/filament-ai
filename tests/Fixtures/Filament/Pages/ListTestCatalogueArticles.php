<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages;

use Filament\Resources\Pages\ListRecords;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestCatalogueResource;

class ListTestCatalogueArticles extends ListRecords
{
    protected static string $resource = TestCatalogueResource::class;
}
