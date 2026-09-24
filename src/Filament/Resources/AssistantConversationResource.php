<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Murkrow\FilamentAi\Agent\Chat\ConversationTranscript;
use Murkrow\FilamentAi\Agent\Chat\StoredConversation;
use Murkrow\FilamentAi\Filament\Concerns\HasAiNavigation;
use Murkrow\FilamentAi\Filament\Resources\AssistantConversationResource\Pages\ListAssistantConversations;
use Murkrow\FilamentAi\Filament\Resources\AssistantConversationResource\Pages\ViewAssistantConversation;
use Throwable;

/**
 * Every conversation anyone had with the assistant, read-only.
 *
 * The question log (QueryResource) records the knowledge base's own answers;
 * the assistant keeps its conversations in laravel/ai's tables instead, which
 * no page showed. This is the page for whoever answers for the assistant:
 * who asked what, what it did to answer -- tool by tool, with arguments and
 * results -- what it cost, and where it failed. Gated like the other
 * knowledge pages (`filament-ai.filament.authorize`), never editable.
 */
class AssistantConversationResource extends Resource
{
    use HasAiNavigation;

    protected static ?string $model = StoredConversation::class;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-chat-bubble-oval-left-ellipsis';

    public static function getModelLabel(): string
    {
        return (string) __('filament-ai::messages.conversations.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('filament-ai::messages.conversations.plural');
    }

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::aiSlug('conversations');
    }

    public static function canAccess(): bool
    {
        return static::canAccessAi() && app(ConversationTranscript::class)->available();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                ->with('participant')
                ->withCount('messages')
                ->withExists(['messages as has_failures' => static fn (Builder $messages): Builder => $messages->where('status', 'failed')]))
            ->columns([
                TextColumn::make('updated_at')
                    ->label(__('filament-ai::messages.conversations.last_activity'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('participant_id')
                    ->label(__('filament-ai::messages.conversations.user'))
                    ->state(static fn (StoredConversation $record): string => $record->participantName()),

                TextColumn::make('title')
                    ->label(__('filament-ai::messages.conversations.title'))
                    ->limit(80)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('messages_count')
                    ->label(__('filament-ai::messages.conversations.messages'))
                    ->numeric()
                    ->sortable(),

                IconColumn::make('has_failures')
                    ->label(__('filament-ai::messages.conversations.failures'))
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-check')
                    ->falseColor('gray'),

                TextColumn::make('created_at')
                    ->label(__('filament-ai::messages.conversations.started'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('participant')
                    ->label(__('filament-ai::messages.conversations.user'))
                    ->options(static fn (): array => static::participantOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null) || ! str_contains((string) $data['value'], '|')) {
                            return $query;
                        }

                        [$type, $id] = explode('|', (string) $data['value'], 2);

                        return $query->where('participant_type', $type)->where('participant_id', $id);
                    }),

                Filter::make('failed')
                    ->label(__('filament-ai::messages.conversations.failed_only'))
                    ->query(static fn (Builder $query): Builder => $query->whereHas('messages', static fn (Builder $messages): Builder => $messages->where('status', 'failed'))),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * @return array<string, string>
     */
    protected static function participantOptions(): array
    {
        try {
            $options = [];

            foreach (StoredConversation::query()->with('participant')->whereNotNull('participant_id')->get(['id', 'participant_type', 'participant_id'])->unique(static fn (StoredConversation $conversation): string => $conversation->participant_type.'|'.$conversation->participant_id) as $conversation) {
                $options[$conversation->participant_type.'|'.$conversation->participant_id] = $conversation->participantName();
            }

            asort($options);

            return $options;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAssistantConversations::route('/'),
            'view' => ViewAssistantConversation::route('/{record}'),
        ];
    }
}
