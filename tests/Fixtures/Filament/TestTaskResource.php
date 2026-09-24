<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Filament;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Murkrow\FilamentAi\Agent\Resources\AgentResource;
use Murkrow\FilamentAi\Agent\Resources\InteractsWithAgent;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages\ListTestTasks;
use Murkrow\FilamentAi\Tests\Fixtures\TestTask;

/**
 * A resource on a tenant panel: its records belong to a team.
 */
class TestTaskResource extends Resource implements AgentResource
{
    use InteractsWithAgent;

    protected static ?string $model = TestTask::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTestTasks::route('/'),
        ];
    }
}
