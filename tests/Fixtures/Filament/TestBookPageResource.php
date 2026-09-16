<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Filament;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages\ListTestBookPages;
use Murkrow\FilamentAi\Tests\Fixtures\TestBookPage;

/**
 * A resource that did NOT opt in. It must never produce an agent tool, however
 * it is configured: exposure is opt-in per resource.
 */
class TestBookPageResource extends Resource
{
    protected static ?string $model = TestBookPage::class;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('content')->searchable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTestBookPages::route('/'),
        ];
    }
}
