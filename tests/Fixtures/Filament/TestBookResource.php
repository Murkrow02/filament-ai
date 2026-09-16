<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Filament;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Murkrow\FilamentAi\Agent\Resources\AgentResource;
use Murkrow\FilamentAi\Agent\Resources\InteractsWithAgent;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages\ListTestBooks;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages\ViewTestBook;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/**
 * An ordinary host resource that opted in to the agent. Nothing in it is
 * written for the agent beyond the interface and the trait: the tools must be
 * derivable from what a Filament resource already declares.
 */
class TestBookResource extends Resource implements AgentResource
{
    use InteractsWithAgent;

    protected static ?string $model = TestBook::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('author')->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('author')->searchable(),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('author')->options([
                    'Anonimo' => 'Anonimo',
                    'Ser Piero' => 'Ser Piero',
                ]),
                TernaryFilter::make('bad_ocr'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTestBooks::route('/'),
            'view' => ViewTestBook::route('/{record}'),
        ];
    }
}
