<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages;

use Filament\Resources\Pages\ListRecords;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;

class ListTestBooks extends ListRecords
{
    protected static string $resource = TestBookResource::class;
}
