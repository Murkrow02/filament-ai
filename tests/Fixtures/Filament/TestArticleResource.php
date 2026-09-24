<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Filament;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Murkrow\FilamentAi\Agent\Resources\AgentResource;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\InteractsWithAgent;
use Murkrow\FilamentAi\Tests\Fixtures\Filament\Pages\ListTestArticles;
use Murkrow\FilamentAi\Tests\Fixtures\TestArticle;

/**
 * A resource whose form leans on what a hand-rolled writer would get wrong.
 */
class TestArticleResource extends Resource implements AgentResource
{
    use InteractsWithAgent;

    protected static ?string $model = TestArticle::class;

    protected static ?string $recordTitleAttribute = 'title';

    /** Flipped by tests to show a field only some users may see. */
    public static bool $showSecret = false;

    public static function agentTools(AgentTools $tools): AgentTools
    {
        return $tools->with(AgentTools::DELETE);
    }

    /**
     * What a create page's mutateFormDataBeforeCreate() would do, e.g. set the
     * owner: page hooks do not run for the agent, this one does.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function agentMutateBeforeCreate(array $data): array
    {
        return [...$data, 'locked' => 'set by the resource'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(100),
            Select::make('status')->options(['draft' => 'Bozza', 'published' => 'Pubblicato'])->required(),
            TextInput::make('secret')->visible(fn (): bool => static::$showSecret),
            TextInput::make('locked')->disabledOn('edit'),
            TextInput::make('password')
                ->password()
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn (?string $state): bool => filled($state)),
            TextInput::make('password_confirmation')->dehydrated(false),
            TextInput::make('code')->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper($state)),
            Select::make('test_book_id')
                ->label('Book')
                ->relationship('book', 'title', fn (Builder $query): Builder => $query->where('bad_ocr', false)),
            DatePicker::make('published_on'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            TextColumn::make('status'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTestArticles::route('/'),
        ];
    }
}
