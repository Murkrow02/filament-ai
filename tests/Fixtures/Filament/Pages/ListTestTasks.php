<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages;

use Filament\Resources\Pages\ListRecords;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestTaskResource;

class ListTestTasks extends ListRecords
{
    protected static string $resource = TestTaskResource::class;
}
