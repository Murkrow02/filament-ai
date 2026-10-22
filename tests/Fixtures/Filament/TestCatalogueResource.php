<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Filament;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Murkrow\FilamentAi\Agent\Resources\AgentResource;
use Murkrow\FilamentAi\Agent\Resources\InteractsWithAgent;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages\ListTestCatalogueArticles;
use Murkrow\FilamentAi\Tests\Fixtures\TestArticle;

/**
 * Articles as a catalogue table would show them: a column and a search that
 * reach through a relationship, sortable columns, and the filters a list
 * really has -- a switch, a date range with its own form, a single text box,
 * a relationship select and the trash.
 */
class TestCatalogueResource extends Resource implements AgentResource
{
    use InteractsWithAgent;

    protected static ?string $model = TestArticle::class;

    protected static ?string $slug = 'catalogue';

    protected static ?string $recordTitleAttribute = 'title';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('book.title')->label('Libro')->searchable()->sortable(),
                TextColumn::make('published_on')->label('Pubblicato il')->date()->sortable(),
            ])
            ->filters([
                Filter::make('published')->label('Pubblicati')->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('published_on')),
                Filter::make('published_on')
                    ->label('Pubblicazione')
                    ->schema([
                        DatePicker::make('from')->label('Dal'),
                        DatePicker::make('until')->label('Al'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('published_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('published_on', '<=', $date))),
                Filter::make('code')
                    ->schema([TextInput::make('code')->label('Codice')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['code'] ?? null, fn (Builder $q, string $code): Builder => $q->where('code', $code))),
                SelectFilter::make('book')->label('Libro')->relationship('book', 'title'),
                TrashedFilter::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTestCatalogueArticles::route('/'),
        ];
    }
}
