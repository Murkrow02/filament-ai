<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages;

use Filament\Resources\Pages\ViewRecord;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\TestBookResource;

class ViewTestBook extends ViewRecord
{
    protected static string $resource = TestBookResource::class;
}
